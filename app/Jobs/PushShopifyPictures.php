<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ShopifyStore;
use App\Models\ShopifySyncLog;
use App\Services\ShopifyImages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pictures for products that are already on Shopify (pushed earlier without pictures):
 * the software's pictures are uploaded only to Shopify products that have NO picture yet.
 * Nothing else on Shopify is changed.
 */
class PushShopifyPictures implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    public function __construct(public ShopifyStore $store, public ShopifySyncLog $log) {}

    public static function query(ShopifyStore $store)
    {
        return Product::where('shopify_store_id', $store->id)->whereNotNull('shopify_product_id')->whereHas('images');
    }

    public function handle(): void
    {
        $errors = [];
        try {
            $this->log->update(['status' => 'processing', 'error_message' => null]);
            if ($scopeError = $this->store->ensureScope('write_products')) throw new \RuntimeException($scopeError);

            $products = self::query($this->store)->with('images')->orderBy('id')->get();
            $this->log->update(['total_products' => $products->count()]);
            $svc = new ShopifyImages($this->store);

            foreach ($products as $p) {
                try {
                    $r = $svc->addToExisting($p);
                    foreach ($r['errors'] as $e) $errors[] = "{$p->sku}: {$e}";
                    if ($r['result'] === 'added') $this->log->increment('synced_products');
                    elseif ($r['result'] === 'failed') $this->log->increment('failed_products');
                    else $this->log->increment('skipped_products');   // already has pictures
                } catch (\Throwable $e) {
                    $this->log->increment('failed_products');
                    $errors[] = "{$p->sku}: {$e->getMessage()}";
                    if (str_contains($e->getMessage(), 'write_products')) throw $e;
                }
            }
            $this->log->update(['status' => 'completed', 'error_message' => $errors ? Str::limit(implode(' | ', $errors), 2000) : null]);
        } catch (\Throwable $e) {
            Log::error("SHOPIFY PICTURES FAILED: {$e->getMessage()}");
            $this->log->update(['status' => 'failed', 'error_message' => Str::limit(implode(' | ', array_merge([$e->getMessage()], $errors)), 2000)]);
        }
    }
}
