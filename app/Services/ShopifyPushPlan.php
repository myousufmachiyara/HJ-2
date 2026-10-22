<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShopifyStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Decides, product by product, what a push to one Shopify store will do — BEFORE anything is sent.
 *
 *   create      brand belongs to this store and none of its SKUs / barcodes are on Shopify
 *   link        a size SKU (or barcode) is already on Shopify → only linked in the software, nothing created
 *   conflict    its SKUs are spread over 2+ different Shopify products → skipped, check by hand
 *   otherStore  brand belongs to another store
 *   noBrand     brand is not in any store's brand list (bulk push skips these)
 *
 * Shopify is read once (every variant SKU + barcode), so the check is exact and fast.
 */
class ShopifyPushPlan
{
    /** @var array<string,array{product:string,variant:string,sku:?string}> UPPER(code) → Shopify ids */
    public array $catalog = [];
    public int $shopifyProducts = 0;

    public Collection $create;
    public Collection $link;
    public Collection $conflict;
    public Collection $otherStore;
    public Collection $noBrand;

    public function __construct(public ShopifyStore $store)
    {
        foreach (['create', 'link', 'conflict', 'otherStore', 'noBrand'] as $k) $this->$k = collect();
    }

    /**
     * @param int[]|null $ids  null = bulk "Push New" (brand must belong to this store);
     *                         ids = ticked on the products list (brands of OTHER stores are still refused)
     */
    public static function build(ShopifyStore $store, ?array $ids = null, ?array $catalog = null): self
    {
        $plan = new self($store);
        if ($catalog !== null) $plan->catalog = $catalog; else $plan->loadCatalog();

        $mine   = $store->brandList();
        $others = [];
        foreach (ShopifyStore::where('id', '!=', $store->id)->get() as $s) {
            foreach ($s->brandList() as $b) $others[$b] = $s->shop_name;
        }

        $products = \App\Jobs\PushProductsToShopify::eligibleQuery($ids)
            ->with(['variations.attributeValues.attribute', 'images', 'category'])->orderBy('id')->get();

        foreach ($products as $p) {
            $brand = ShopifyStore::normalizeBrand($p->brand);
            $inMine = $mine ? in_array($brand, $mine, true) : ($ids !== null || !$others);   // no list + only store → all

            if (!$inMine) {
                if (isset($others[$brand])) { $plan->otherStore->push((object) ['product' => $p, 'store' => $others[$brand]]); continue; }
                if ($ids === null)          { $plan->noBrand->push((object) ['product' => $p]); continue; }
            }

            $hits = $plan->matchesFor($p);
            $shopifyIds = collect($hits)->pluck('product')->unique()->values();

            if ($shopifyIds->count() > 1) {
                $plan->conflict->push((object) ['product' => $p, 'shopify_ids' => $shopifyIds->all()]);
            } elseif ($shopifyIds->count() === 1) {
                $codes = self::codesOf($p);
                $plan->link->push((object) [
                    'product'    => $p,
                    'shopify_id' => $shopifyIds[0],
                    'variants'   => $hits,   // variation id → Shopify variant id
                    'missing'    => max(0, count($codes) - count($hits)),
                ]);
            } else {
                $plan->create->push((object) ['product' => $p]);
            }
        }

        return $plan;
    }

    /** Size SKUs / barcodes of a product (the product's own when it has no sizes): [variation id|0 => [codes]]. */
    public static function codesOf(Product $p): array
    {
        if ($p->variations->isEmpty()) {
            return [0 => array_values(array_filter([$p->sku, $p->barcode]))];
        }
        $out = [];
        foreach ($p->variations as $v) $out[$v->id] = array_values(array_filter([$v->sku, $v->barcode]));
        return $out;
    }

    /** [variation id|0 => ['product' => shopify product id, 'variant' => shopify variant id]] for codes found on Shopify. */
    public function matchesFor(Product $p): array
    {
        $hits = [];
        foreach (self::codesOf($p) as $vid => $codes) {
            foreach ($codes as $c) {
                if ($hit = $this->catalog[self::key($c)] ?? null) { $hits[$vid] = $hit; break; }
            }
        }
        return $hits;
    }

    /** Remember codes of a product just created on Shopify (so a repeated SKU in the same run is not created twice). */
    public function remember(Product $p, string $shopifyId): void
    {
        foreach (self::codesOf($p) as $codes) {
            foreach ($codes as $c) $this->catalog[self::key($c)] ??= ['product' => $shopifyId, 'variant' => '', 'sku' => $c];
        }
    }

    public static function key(string $code): string
    {
        return strtoupper(trim($code));
    }

    /** Every variant on the Shopify store (any status: active, draft, archived). */
    public function loadCatalog(): void
    {
        $after = null;
        $products = [];
        do {
            $data = $this->store->graphql(<<<'GQL'
                query Catalog($after: String) {
                  productVariants(first: 250, after: $after) {
                    pageInfo { hasNextPage endCursor }
                    nodes { id sku barcode product { id } }
                  }
                }
                GQL, ['after' => $after]);

            $page = $data['productVariants'] ?? [];
            foreach ($page['nodes'] ?? [] as $n) {
                $hit = [
                    'product' => Str::afterLast($n['product']['id'] ?? '', '/'),
                    'variant' => Str::afterLast($n['id'] ?? '', '/'),
                    'sku'     => $n['sku'] ?? null,
                ];
                $products[$hit['product']] = true;
                foreach ([$n['sku'] ?? '', $n['barcode'] ?? ''] as $code) {
                    if (trim((string) $code) !== '') $this->catalog[self::key($code)] ??= $hit;
                }
            }
            $after = ($page['pageInfo']['hasNextPage'] ?? false) ? ($page['pageInfo']['endCursor'] ?? null) : null;
        } while ($after);

        $this->shopifyProducts = count($products);
    }
}
