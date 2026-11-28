<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ShopifyStore;
use App\Models\ShopifySyncLog;
use App\Services\ShopifyMissingVariants;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Add the chosen missing sizes / SKUs to products that already exist on Shopify. */
class PushMissingShopifyVariants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    /** @param array<int,int[]> $selection product id => variation ids */
    public function __construct(public ShopifyStore $store, public ShopifySyncLog $log, public array $selection) {}

    public function handle(): void
    {
        $errors = [];
        try {
            $this->log->update(['status' => 'processing', 'error_message' => null, 'total_products' => count($this->selection)]);
            if ($scopeError = $this->store->ensureScope('write_products')) throw new \RuntimeException($scopeError);

            $svc = new ShopifyMissingVariants($this->store);
            foreach ($this->selection as $productId => $variationIds) {
                $p = Product::with(['variations.attributeValues.attribute'])->find($productId);
                if (!$p || (int) $p->shopify_store_id !== (int) $this->store->id || !$p->shopify_product_id) {
                    $this->log->increment('skipped_products');
                    continue;
                }
                try {
                    $r = $svc->push($p, array_map('intval', $variationIds));
                    foreach ($r['errors'] as $e) $errors[] = $e;
                    if ($r['created'] > 0) $this->log->increment('synced_products');
                    elseif ($r['errors'])   $this->log->increment('failed_products');
                    else                    $this->log->increment('skipped_products');
                } catch (\Throwable $e) {
                    $this->log->increment('failed_products');
                    $errors[] = "{$p->sku}: {$e->getMessage()}";
                    if (str_contains($e->getMessage(), 'write_products')) throw $e;
                }
            }
            $this->log->update(['status' => 'completed', 'error_message' => $errors ? Str::limit(implode(' | ', $errors), 2000) : null]);
        } catch (\Throwable $e) {
            Log::error("SHOPIFY MISSING SIZES FAILED: {$e->getMessage()}");
            $this->log->update(['status' => 'failed', 'error_message' => Str::limit(implode(' | ', array_merge([$e->getMessage()], $errors)), 2000)]);
        }
    }
}
