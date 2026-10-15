<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ShopifyStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Puts Shopify's real variant SKUs (and barcodes) on the software's sizes.
 *
 * Runs directly — no queue worker involved — and explains every size it cannot fix.
 *
 *   php artisan hj:shopify-fix-skus --dry     (only report)
 *   php artisan hj:shopify-fix-skus           (fix)
 *   php artisan hj:shopify-fix-skus 3         (one store only)
 */
class ShopifyFixSkus extends Command
{
    protected $signature = 'hj:shopify-fix-skus {store? : Shopify store id (default: all connected stores)} {--dry : Only report, change nothing}';

    protected $description = 'Make software size SKUs/barcodes the same as the Shopify variant SKUs/barcodes';

    private array $conflicts = [];
    private array $missing   = [];

    public function handle(): int
    {
        $stores = ShopifyStore::query()
            ->when($this->argument('store'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($stores->isEmpty()) {
            $this->error('No Shopify store found.');
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        if ($dry) $this->warn('DRY RUN — nothing will be changed.');

        foreach ($stores as $store) {
            $this->newLine();
            $this->info("Store #{$store->id} {$store->shop_name} ({$store->shop_url})");

            try {
                $products = $this->fetchAll($store);
            } catch (\Throwable $e) {
                $this->error('  Could not read products from Shopify: ' . $e->getMessage());
                continue;
            }

            $count = ['ok' => 0, 'fixed' => 0, 'missing' => 0, 'conflict' => 0, 'no_sku' => 0, 'released' => 0];

            foreach ($products as $shp) {
                foreach ($shp['variants'] ?? [] as $v) {
                    $this->fixVariant($store, $shp, $v, $dry, $count);
                }
            }

            $this->line(sprintf(
                '  Shopify products: %d | already same: %d | %s: %d | old deleted copies freed: %d | not in software: %d | blocked: %d | no SKU on Shopify: %d',
                count($products), $count['ok'], $dry ? 'would fix' : 'fixed', $count['fixed'],
                $count['released'], $count['missing'], $count['conflict'], $count['no_sku']
            ));
        }

        if ($this->conflicts) {
            $this->newLine();
            $this->warn('Blocked — the Shopify SKU / barcode is already used by another LIVE item in the software:');
            $this->table(['Shopify SKU', 'Software size now', 'Used by'], array_slice($this->conflicts, 0, 100));
            if (count($this->conflicts) > 100) $this->line('  … and ' . (count($this->conflicts) - 100) . ' more.');
            $this->line('  Fix: delete / rename the other item (or its size), then run this command again.');
        }

        if ($this->missing) {
            $this->newLine();
            $this->warn('Shopify sizes not in the software yet (run Sync Now on the store first):');
            $this->table(['Shopify product', 'Shopify SKU'], array_slice($this->missing, 0, 50));
            if (count($this->missing) > 50) $this->line('  … and ' . (count($this->missing) - 50) . ' more.');
        }

        return self::SUCCESS;
    }

    private function fixVariant(ShopifyStore $store, array $shp, array $v, bool $dry, array &$count): void
    {
        $real = trim((string) ($v['sku'] ?? ''));
        if ($real === '') { $count['no_sku']++; return; }

        $vid   = (string) $v['id'];
        $local = $this->localFor($store, $shp, $v, $real);

        if (!$local) {
            $count['missing']++;
            $this->missing[] = [$shp['title'] ?? $shp['id'], $real];
            return;
        }

        $barcode = $this->cleanBarcode($v['barcode'] ?? '') ?: $real;   // no barcode on Shopify → same as SKU

        if ($local->sku === $real && $local->barcode === $barcode && (string) $local->shopify_variant_id === $vid) {
            $count['ok']++;
            return;
        }

        // Who else holds the SKU? Dead copies are freed, live ones block.
        $blockers = [];
        foreach (ProductVariation::withTrashed()->with(['product' => fn ($q) => $q->withTrashed()])
                     ->where('id', '!=', $local->id)
                     ->where(fn ($q) => $q->where('sku', $real)->orWhere('barcode', $real))->get() as $h) {
            $dead = $h->trashed() || !$h->product || $h->product->trashed();
            if ($dead) {
                if (!$dry) {
                    if ($h->sku === $real)     $h->sku = $real . '-DEL' . $h->id;
                    if ($h->barcode === $real) $h->barcode = null;
                    $h->saveQuietly();
                }
                $count['released']++;
                continue;
            }
            $blockers[] = $h;
        }

        if ($blockers) {
            $count['conflict']++;
            $h = $blockers[0];
            $who = '#' . $h->product_id . ' ' . ($h->product->name ?? '?') . ' / size SKU ' . $h->sku;
            if ($h->product?->shopify_store_id) {
                $who .= ' (from Shopify store #' . $h->product->shopify_store_id
                    . ((string) $h->shopify_variant_id === $vid ? ', SAME Shopify size imported twice' : '') . ')';
            } else {
                $who .= ' (made in the software)';
            }
            $this->conflicts[] = [$real, $local->sku, $who];
            return;
        }

        // Barcode: Shopify's own if nobody else (alive) uses it, else the SKU.
        if ($barcode !== $real && ProductVariation::where('id', '!=', $local->id)->where('barcode', $barcode)->exists()) {
            $barcode = $real;
        }

        if (!$dry) {
            $local->sku = $real;
            $local->barcode = $barcode;
            $local->shopify_variant_id = $vid;
            $local->saveQuietly();
        }
        $count['fixed']++;
    }

    /** The software size that belongs to this Shopify variant. */
    private function localFor(ShopifyStore $store, array $shp, array $v, string $real): ?ProductVariation
    {
        $vid = (string) $v['id'];

        $productIds = Product::where('shopify_product_id', (string) $shp['id'])->pluck('id');

        return ProductVariation::whereIn('product_id', $productIds)->where('shopify_variant_id', $vid)->first()
            ?? ProductVariation::where('shopify_variant_id', $vid)->first()
            ?? ProductVariation::where('sku', 'SHP-' . $store->id . '-V-' . $vid)->first()
            ?? ProductVariation::where('sku', 'like', 'SHP-%-V-' . $vid)->first()
            ?? ProductVariation::whereIn('product_id', $productIds)->where('sku', $real)->first();
    }

    private function cleanBarcode($raw): string
    {
        $raw = trim((string) $raw);
        if (preg_match('/^\d+(\.\d+)?e\+?\d+$/i', $raw)) return number_format((float) $raw, 0, '', '');
        return $raw;
    }

    private function fetchAll(ShopifyStore $store): array
    {
        $token = $store->getValidAccessToken();
        if (!$token) throw new \RuntimeException('no valid access token — reconnect the store.');

        $api = config('services.shopify.api_version', '2026-07');
        $url = "https://{$store->shop_url}/admin/api/{$api}/products.json?limit=250&fields=id,title,variants";
        $all = [];

        while ($url) {
            $res = Http::timeout(60)->withHeaders(['X-Shopify-Access-Token' => $token])->get($url);
            if (!$res->successful()) {
                throw new \RuntimeException("Shopify API error {$res->status()}: " . substr($res->body(), 0, 200));
            }
            $all = array_merge($all, $res->json('products') ?? []);
            $url = preg_match('/<([^>]+)>;\s*rel="next"/', (string) $res->header('Link'), $m) ? $m[1] : null;
            if ($url) usleep(500_000);
            $this->output->write("\r  fetched " . count($all) . ' products…');
        }
        $this->output->write("\n");

        return $all;
    }
}
