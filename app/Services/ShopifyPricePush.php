<?php

namespace App\Services;

use App\Jobs\PushPricesToShopify;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ShopifyStore;
use App\Models\ShopifySyncLog;

/**
 * Shared by the "Push Prices to Shopify" page and the Bulk Price Update page:
 *   describe()  – what would be sent for a list of codes / ids (no Shopify call)
 *   dispatch()  – queue one price push per Shopify store, with a Sync History row each
 */
class ShopifyPricePush
{
    public function __construct(protected ItemCodeLookup $lookup) {}

    /**
     * SKU / barcode list → one row per size (or product without sizes).
     * @return array{items: array, errors: array}
     */
    public function resolveCodes(array $codes): array
    {
        $items = $errors = [];
        $seen  = [];

        foreach ($codes as $code) {
            $code = trim((string) $code);
            if ($code === '') continue;

            $found = $this->lookup->resolve($code);
            if (!$found['success']) { $errors[] = $found['message']; continue; }

            $src       = $found['type'] === 'variation' ? $found['variation'] : $found['product'];
            $productId = $found['type'] === 'variation' ? $src['product_id'] : $src['id'];
            $variation = $found['type'] === 'variation' ? $src['id'] : null;

            $key = $productId . '-' . ($variation ?? 0);
            if (isset($seen[$key])) continue;                       // same item given twice (SKU and barcode)
            $seen[$key] = true;

            $items[] = ['product_id' => (int) $productId, 'variation_id' => $variation ? (int) $variation : null];
        }

        return ['items' => $items, 'errors' => $errors];
    }

    /** Rows for the review table. */
    public function describe(array $refs): array
    {
        $products = Product::with('variations')->whereIn('id', collect($refs)->pluck('product_id')->unique())->get()->keyBy('id');
        $stores   = ShopifyStore::pluck('shop_name', 'id');

        return collect($refs)->map(function ($r) use ($products, $stores) {
            $p = $products[$r['product_id']] ?? null;
            if (!$p) return null;

            $v = $r['variation_id'] ? $p->variations->firstWhere('id', $r['variation_id']) : null;
            if ($r['variation_id'] && !$v) return null;

            if ($v) { $v->setRelation('product', $p); $price = $v->salePrice(); $compare = $v->comparePrice(); }
            else    { $price = (float) ($p->selling_price ?? 0); $compare = (float) $p->compare_at_price > 0 ? (float) $p->compare_at_price : null; }

            $linked = $p->shopify_product_id && isset($stores[$p->shopify_store_id]);
            $note   = null;
            if (!$linked)       $note = 'Not on Shopify yet — push the product first';
            elseif ($price <= 0) $note = 'No selling price';

            return [
                'product_id'   => $p->id,
                'variation_id' => $v?->id,
                'sku'          => $v?->sku ?? $p->sku,
                'name'         => $p->name,
                'price'        => $price,
                'compare_at'   => $compare,
                'store'        => $linked ? $stores[$p->shopify_store_id] : null,
                'ok'           => $linked && $price > 0,
                'note'         => $note,
            ];
        })->filter()->values()->all();
    }

    /**
     * Queue the price push. Each product goes to the store it is linked to.
     * @param  array<int,array{product_id:int,variation_id:?int}> $refs
     * @return array{queued:int, stores:array, skipped:int}
     */
    public function dispatch(array $refs): array
    {
        $products = Product::whereIn('id', collect($refs)->pluck('product_id')->unique())->get(['id', 'shopify_store_id', 'shopify_product_id'])->keyBy('id');
        $byStore  = [];
        $skipped  = 0;

        foreach ($refs as $r) {
            $p = $products[$r['product_id']] ?? null;
            if (!$p || !$p->shopify_product_id || !$p->shopify_store_id) { $skipped++; continue; }
            $byStore[$p->shopify_store_id][] = ['product_id' => (int) $r['product_id'], 'variation_id' => $r['variation_id'] ? (int) $r['variation_id'] : null];
        }

        $names = [];
        $queued = 0;
        foreach ($byStore as $storeId => $list) {
            $store = ShopifyStore::find($storeId);
            if (!$store || !$store->isConnected()) { $skipped += count($list); continue; }

            $log = ShopifySyncLog::create([
                'shopify_store_id' => $store->id,
                'status'           => 'pending',
                'direction'        => 'prices',
                'total_products'   => count($list),
            ]);
            PushPricesToShopify::dispatch($store, $log, $list);
            $names[] = $store->shop_name . ' (' . count($list) . ')';
            $queued += count($list);
        }

        return ['queued' => $queued, 'stores' => $names, 'skipped' => $skipped];
    }
}
