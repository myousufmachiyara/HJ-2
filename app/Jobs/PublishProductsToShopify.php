<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ShopifyStore;
use App\Models\ShopifySyncLog;
use App\Services\ShopifyPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Publish every product that was pushed from the software to the store's Online Store channel,
 * so Active products show on the website. Safe to run again (publishing twice changes nothing).
 */
class PublishProductsToShopify implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    public function __construct(public ShopifyStore $store, public ShopifySyncLog $log) {}

    public static function query(ShopifyStore $store)
    {
        return Product::where('shopify_store_id', $store->id)->whereNotNull('shopify_product_id');
    }

    public function handle(): void
    {
        $errors = [];
        try {
            $this->log->update(['status' => 'processing', 'error_message' => null]);
            $ids = self::query($this->store)->orderBy('id')->get(['id', 'sku', 'shopify_product_id']);
            $this->log->update(['total_products' => $ids->count()]);

            $pub = new ShopifyPublisher($this->store);
            if ($problem = $pub->problem()) throw new \RuntimeException($problem);

            foreach ($ids as $p) {
                $err = $pub->publish((string) $p->shopify_product_id);
                if ($err) { $this->log->increment('failed_products'); $errors[] = "{$p->sku}: {$err}"; }
                else      { $this->log->increment('synced_products'); }
            }
            $this->log->update(['status' => 'completed', 'error_message' => $errors ? Str::limit(implode(' | ', $errors), 2000) : null]);
        } catch (\Throwable $e) {
            Log::error("SHOPIFY PUBLISH FAILED: {$e->getMessage()}");
            $this->log->update(['status' => 'failed', 'error_message' => Str::limit(implode(' | ', array_merge([$e->getMessage()], $errors)), 2000)]);
        }
    }
}
