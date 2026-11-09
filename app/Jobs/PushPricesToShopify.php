<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ShopifyStore;
use App\Models\ShopifySyncLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Send selling + discount prices of chosen sizes from the software to Shopify.
 *
 *   Shopify "Price"            ← the size's selling price      (falls back to the product's)
 *   Shopify "Compare-at price" ← the size's compare-at price   (blank here = cleared on Shopify,
 *                                                                so a finished sale really ends)
 *
 *   - Only products already linked to THIS store (shopify_store_id / shopify_product_id) are touched.
 *   - Only price and compare-at price are sent. Title, stock, pictures etc. are never changed.
 *   - One request per Shopify product (all its chosen sizes together).
 *   - Progress / failures are written to the Sync History (direction "prices").
 */
class PushPricesToShopify implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries   = 1;

    /** @param array<int,array{product_id:int,variation_id:?int}> $refs */
    public function __construct(
        public ShopifyStore $store,
        public ShopifySyncLog $log,
        public array $refs,
    ) {}

    public function handle(): void
    {
        Log::info("SHOPIFY PRICE PUSH STARTED — Store: {$this->store->shop_name}, items: " . count($this->refs));
        $errors = [];

        try {
            $this->log->update(['status' => 'processing', 'error_message' => null, 'total_products' => count($this->refs)]);

            if ($scopeError = $this->store->ensureScope('write_products')) {
                throw new \RuntimeException($scopeError);
            }

            foreach (collect($this->refs)->groupBy('product_id') as $productId => $group) {
                $count = $group->count();
                try {
                    $note = $this->pushProduct((int) $productId, $group->values()->all(), $errors);
                    $this->log->increment('synced_products', $count - $note['failed']);
                    if ($note['failed']) $this->log->increment('failed_products', $note['failed']);
                } catch (\Throwable $e) {
                    $this->log->increment('failed_products', $count);
                    $errors[] = "Product #{$productId}: {$e->getMessage()}";
                    Log::error("✗ price push product {$productId}: {$e->getMessage()}");

                    if (str_contains($e->getMessage(), 'write_products')) throw $e;   // every other one fails the same way
                }
            }

            $this->log->update([
                'status'        => 'completed',
                'error_message' => $errors ? Str::limit(implode(' | ', $errors), 2000) : null,
            ]);
        } catch (\Throwable $e) {
            Log::error("SHOPIFY PRICE PUSH FAILED: {$e->getMessage()}");
            $this->log->update([
                'status'        => 'failed',
                'error_message' => Str::limit(implode(' | ', array_merge([$e->getMessage()], $errors)), 2000),
            ]);
        }
    }

    /**
     * One Shopify product: update all its chosen sizes in one call.
     * @return array{failed:int}
     */
    private function pushProduct(int $productId, array $refs, array &$errors): array
    {
        $product = Product::with('variations')->find($productId);
        $failed  = 0;
        $fail    = function (string $msg) use (&$failed, &$errors) { $failed++; $errors[] = $msg; };

        if (!$product) {
            foreach ($refs as $r) $fail("Product #{$productId}: not found in the software");
            return ['failed' => $failed];
        }
        if (!$product->shopify_product_id || (int) $product->shopify_store_id !== (int) $this->store->id) {
            foreach ($refs as $r) $fail("{$product->sku}: not linked to {$this->store->shop_name} — not sent");
            return ['failed' => $failed];
        }

        $productGid = 'gid://shopify/Product/' . $product->shopify_product_id;
        $shopify    = null;      // read lazily: only when a size has no stored Shopify id
        $inputs     = [];

        foreach ($refs as $r) {
            $v = !empty($r['variation_id']) ? $product->variations->firstWhere('id', (int) $r['variation_id']) : null;
            $label = $v?->sku ?? $product->sku;
            if (!empty($r['variation_id']) && !$v) { $fail("{$label}: size not found in the software"); continue; }

            if ($v) { $v->setRelation('product', $product); $price = $v->salePrice(); $compare = $v->comparePrice(); }
            else {
                $price   = (float) ($product->selling_price ?? 0);
                $compare = (float) $product->compare_at_price > 0 ? (float) $product->compare_at_price : null;
            }
            if ($price <= 0) { $fail("{$label}: no selling price in the software — not sent"); continue; }

            $variantId = $v?->shopify_variant_id;
            if (!$variantId) {
                $shopify ??= $this->shopifyVariants($productGid);
                if ($v) {
                    $hit = $shopify['bySku'][strtoupper((string) $v->sku)] ?? ($v->barcode ? ($shopify['byBarcode'][strtoupper((string) $v->barcode)] ?? null) : null);
                } else {
                    $hit = count($shopify['all']) === 1 ? $shopify['all'][0] : null;      // product without sizes = Shopify's single default variant
                }
                if (!$hit) { $fail("{$label}: this size was not found on the Shopify product"); continue; }
                $variantId = Str::afterLast($hit, '/');
                if ($v) ProductVariation::whereKey($v->id)->update(['shopify_variant_id' => $variantId]);
            }

            $inputs[] = [
                'id'             => 'gid://shopify/ProductVariant/' . $variantId,
                'price'          => number_format($price, 2, '.', ''),
                'compareAtPrice' => $compare !== null ? number_format($compare, 2, '.', '') : null,   // null clears it on Shopify
                '_label'         => $label,
            ];
        }

        foreach (array_chunk($inputs, 100) as $chunk) {
            $data = $this->store->graphql(<<<'GQL'
                mutation UpdatePrices($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
                  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                    productVariants { id price compareAtPrice }
                    userErrors { field message }
                  }
                }
                GQL, [
                'productId' => $productGid,
                'variants'  => array_map(fn ($i) => collect($i)->except('_label')->all(), $chunk),
            ]);

            $userErrors = $data['productVariantsBulkUpdate']['userErrors'] ?? [];
            if ($userErrors) {
                $failed += count($chunk);
                $errors[] = "{$product->sku}: " . collect($userErrors)->pluck('message')->filter()->implode('; ');
                continue;
            }
            foreach ($chunk as $i) Log::info("✓ price {$i['_label']}: {$i['price']} / compare-at " . ($i['compareAtPrice'] ?? '—'));
        }

        return ['failed' => $failed];
    }

    /** Shopify's variants of one product: SKU / barcode → variant gid. */
    private function shopifyVariants(string $productGid): array
    {
        $data = $this->store->graphql(<<<'GQL'
            query ProductVariants($id: ID!) {
              product(id: $id) { variants(first: 250) { nodes { id sku barcode } } }
            }
            GQL, ['id' => $productGid]);

        $out = ['all' => [], 'bySku' => [], 'byBarcode' => []];
        foreach ($data['product']['variants']['nodes'] ?? [] as $n) {
            if (empty($n['id'])) continue;
            $out['all'][] = $n['id'];
            if (!empty($n['sku']))     $out['bySku'][strtoupper($n['sku'])] = $n['id'];
            if (!empty($n['barcode'])) $out['byBarcode'][strtoupper($n['barcode'])] = $n['id'];
        }
        return $out;
    }
}
