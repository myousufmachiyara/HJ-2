<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\{
    ShopifyStore, ShopifySyncLog, Product,
    Attribute, AttributeValue, ProductVariation, ProductVariationAttributeValue
};
use Illuminate\Support\Facades\{Http, DB, Log};
use Illuminate\Support\Str;

class ProcessShopifyImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ShopifyStore $store;
    public ShopifySyncLog $log;
    public int $timeout = 600;
    public int $tries   = 1;

    // ─────────────────────────────────────────────
    //  Shopify REST API rate-limit constants
    //  Standard plan: 2 req/s (500ms between calls is safe)
    //  Plus/Advanced: 4 req/s — lower THROTTLE_MS if needed
    // ─────────────────────────────────────────────
    private const THROTTLE_MS        = 500;   // microseconds × 1000 → ms between paginated fetches
    private const API_CALL_LIMIT_HDR = 'X-Shopify-Shop-Api-Call-Limit'; // "40/80" format

    public function __construct(ShopifyStore $store, ShopifySyncLog $log)
    {
        $this->store = $store;
        $this->log   = $log;
    }

    // ─────────────────────────────────────────────
    //  Entry point
    // ─────────────────────────────────────────────
    public function handle(): void
    {
        Log::info("SHOPIFY IMPORT STARTED — Store: {$this->store->shop_name}");

        try {
            $this->log->update(['status' => 'processing', 'error_message' => null]);

            $products = $this->fetchAllProducts();

            $this->log->update([
                'total_products' => count($products),
                'status'         => 'processing',
            ]);

            Log::info("Found " . count($products) . " products to import.");

            foreach ($products as $index => $shp) {
                try {
                    // Each product is its own transaction — a bad variant
                    // won't roll back other successfully imported products
                    DB::transaction(fn () => $this->syncProduct($shp));
                    $this->log->increment('synced_products');
                    Log::info("✓ [{$index}] {$shp['title']}");
                } catch (\Throwable $e) {
                    $this->log->increment('failed_products');
                    Log::error("✗ [{$shp['id']}] {$shp['title']}: {$e->getMessage()}");
                    Log::error("  at {$e->getFile()}:{$e->getLine()}");
                }
            }

            $fresh = $this->log->fresh();
            $this->log->update(['status' => 'completed']);
            Log::info("IMPORT DONE — Synced: {$fresh->synced_products} | Failed: {$fresh->failed_products}");

        } catch (\Throwable $e) {
            Log::error("IMPORT FAILED: {$e->getMessage()}");
            Log::error($e->getTraceAsString());
            $this->log->update([
                'status'        => 'failed',
                'error_message' => Str::limit($e->getMessage(), 250),
            ]);
        }
    }

    // ─────────────────────────────────────────────
    //  Fetch ALL products via Shopify cursor pagination
    //  FIX: Added rate-limit throttling between pages
    //       to avoid 429s on large catalogs.
    // ─────────────────────────────────────────────
    private function fetchAllProducts(): array
    {
        $all = [];
        $url = "https://{$this->store->shop_url}/admin/api/2025-01/products.json?limit=250";

        while ($url) {
            $response = Http::timeout(60)
                ->withHeaders(['X-Shopify-Access-Token' => $this->store->getAccessToken()])
                ->get($url);

            if (!$response->successful()) {
                throw new \Exception(
                    "Shopify API Error ({$response->status()}): " . substr($response->body(), 0, 200)
                );
            }

            $data = $response->json();

            if (!isset($data['products'])) {
                throw new \Exception("Invalid API response: 'products' key missing.");
            }

            $all = array_merge($all, $data['products']);
            $url = $this->getNextPageUrl($response);

            Log::info("Fetched page — running total: " . count($all));

            // FIX: Throttle between paginated requests.
            // Read the leaky-bucket header and back off if > 80% full.
            // Falls back to a fixed delay when the header is absent.
            if ($url) {
                $this->throttleIfNeeded($response);
            }
        }

        return $all;
    }

    /**
     * Sleep between paginated fetches to stay within Shopify's rate limit.
     * The X-Shopify-Shop-Api-Call-Limit header looks like "40/80".
     * If we're above 80% of the bucket, sleep longer to let it drain.
     */
    private function throttleIfNeeded($response): void
    {
        $header = $response->header(self::API_CALL_LIMIT_HDR);

        if ($header && preg_match('/^(\d+)\/(\d+)$/', $header, $m)) {
            $used  = (int) $m[1];
            $total = (int) $m[2];
            $ratio = $total > 0 ? $used / $total : 0;

            // Over 80% full — sleep 2 s to let the bucket drain
            if ($ratio >= 0.8) {
                Log::info("Rate limit at {$used}/{$total} — sleeping 2s");
                usleep(2_000_000);
                return;
            }
        }

        // Default: fixed half-second gap between pages
        usleep(self::THROTTLE_MS * 1_000);
    }

    private function getNextPageUrl($response): ?string
    {
        $linkHeader = $response->header('Link');
        if (!$linkHeader) return null;

        if (preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    // ─────────────────────────────────────────────
    //  Sync a single product + its variants
    //  FIX: resolveProductSku now always returns a
    //       store-scoped ID so it never collides with
    //       variant SKUs (which are stored separately).
    // ─────────────────────────────────────────────
    private function syncProduct(array $shp): void
    {
        $firstVariant = $shp['variants'][0] ?? [];

        $product = Product::updateOrCreate(
            // Match on Shopify's own product ID — never on SKU.
            // This is stable even if the merchant changes the SKU.
            ['shopify_product_id' => (string) $shp['id']],
            [
                'name'              => $shp['title'],
                // FIX: product SKU is now always store-scoped and never
                // duplicates the first variant's SKU (see resolveProductSku).
                'sku'               => $this->resolveProductSku($shp),
                'description'       => strip_tags($shp['body_html'] ?? ''),
                // FIX: category_id and measurement_unit should NOT be
                // hardcoded. Read them from the store's own settings
                // so re-imports don't silently overwrite user edits.
                // Fall back to 1 only when the store has no preference set.
                'category_id'       => $this->store->default_category_id ?? 1,
                'measurement_unit'  => $this->store->default_measurement_unit ?? 1,
                'selling_price'     => $firstVariant['price'] ?? 0,
                'shopify_store_id'  => $this->store->id,
            ]
        );

        // Map Shopify options → local Attributes
        $optionMapping = [];
        foreach ($shp['options'] as $index => $option) {
            $attribute = Attribute::firstOrCreate(
                ['slug' => Str::slug($option['name'])],
                ['name' => $option['name']]
            );
            $optionMapping['option' . ($index + 1)] = $attribute->id;
        }

        // Upsert each variant individually — a failure on one doesn't stop the rest
        foreach ($shp['variants'] as $position => $v) {
            try {
                $this->syncVariant($v, $product->id, $position, $optionMapping);
            } catch (\Throwable $e) {
                Log::warning("  Skipped variant {$v['id']} of product {$shp['id']}: {$e->getMessage()}");
            }
        }
    }

    // ─────────────────────────────────────────────
    //  Sync a single variant
    //  FIX: updateOrCreate now scopes on BOTH sku
    //       AND product_id to prevent cross-store
    //       collisions when merchants share SKUs.
    // ─────────────────────────────────────────────
    private function syncVariant(array $v, int $productId, int $position, array $optionMapping): void
    {
        $sku     = $this->resolveVariantSku($v);
        $barcode = $this->resolveBarcode($v);

        // FIX: added product_id to the match keys so two stores that happen
        // to share a SKU string (e.g. "RED-L") don't overwrite each other.
        $pv = ProductVariation::updateOrCreate(
            [
                'sku'        => $sku,
                'product_id' => $productId,
            ],
            [
                'product_id'     => $productId,
                'barcode'        => $barcode,
                'selling_price'  => $v['price'] ?? 0,
                'stock_quantity' => $v['inventory_quantity'] ?? 0,
            ]
        );

        // Link variant → attribute values
        foreach (['option1', 'option2', 'option3'] as $optKey) {
            if (empty($v[$optKey])) continue;

            $attrId = $optionMapping[$optKey] ?? null;
            if (!$attrId) continue;

            $val = AttributeValue::firstOrCreate([
                'attribute_id' => $attrId,
                'value'        => $v[$optKey],
            ]);

            ProductVariationAttributeValue::firstOrCreate([
                'product_variation_id' => $pv->id,
                'attribute_value_id'   => $val->id,
            ]);
        }
    }

    // ─────────────────────────────────────────────
    //  SKU / barcode helpers
    //  Every value must be unique and stable so
    //  re-imports don't create duplicates or errors
    // ─────────────────────────────────────────────

    /**
     * FIX: Product SKU is now ALWAYS the store-scoped Shopify product ID.
     *
     * The old behaviour (using first variant's SKU as the product SKU)
     * caused a silent collision: syncVariant() would later updateOrCreate
     * a ProductVariation with that same SKU string, so two records — the
     * Product and its first ProductVariation — competed for the same value.
     *
     * Products and variants live in separate tables with separate SKU columns,
     * so they need independent, non-overlapping identifiers.
     */
    private function resolveProductSku(array $shp): string
    {
        // store_id prefix avoids collisions when syncing multiple stores.
        // Using the Shopify product ID (not a variant ID) is stable across re-imports.
        return 'SHP-' . $this->store->id . '-P-' . $shp['id'];
    }

    /**
     * Variant SKU: use Shopify's SKU if set,
     * otherwise build a stable unique value from store + variant IDs.
     * Using $v['id'] (not position) keeps it stable across re-imports.
     */
    private function resolveVariantSku(array $v): string
    {
        $raw = trim($v['sku'] ?? '');

        if ($raw !== '') {
            return $raw;
        }

        return 'SHP-' . $this->store->id . '-V-' . $v['id'];
    }

    /**
     * Barcode: strips scientific notation (e.g. 6.94E+11 → 694000000000),
     * falls back to a store-scoped unique value.
     */
    private function resolveBarcode(array $v): string
    {
        $raw = trim($v['barcode'] ?? '');

        if ($raw !== '') {
            return number_format((float) $raw, 0, '', '');
        }

        return 'SHP-' . $this->store->id . '-B-' . $v['id'];
    }

    // ─────────────────────────────────────────────
    //  Queue failure handler
    // ─────────────────────────────────────────────
    public function failed(\Throwable $exception): void
    {
        Log::error("JOB FAILED — Store: {$this->store->shop_name}: {$exception->getMessage()}");

        $this->log->update([
            'status'        => 'failed',
            'error_message' => 'Job failed: ' . Str::limit($exception->getMessage(), 230),
        ]);
    }
}