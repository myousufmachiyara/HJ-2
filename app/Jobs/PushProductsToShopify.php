<?php

namespace App\Jobs;

use App\Models\Product;
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
 * Push products from the software to Shopify (software → Shopify).
 *
 *   - Only NEW products: finished goods, active, not yet linked to any Shopify store.
 *     Products already on Shopify are never changed from here.
 *   - Created as DRAFT on Shopify, with title, description, brand (vendor),
 *     category (product type), sizes (options/variants), each size's selling price,
 *     compare-at price, SKU and barcode, plus product images when the server is public.
 *   - Stock is NOT sent.
 *   - If Shopify already has a variant with the same SKU, the product is only linked
 *     (no duplicate is created).
 *   - After creation the local product is linked (shopify_store_id / shopify_product_id),
 *     so it is never pushed twice and a later "Sync Now" updates the same product.
 */
class PushProductsToShopify implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries   = 1;

    /** @param int[]|null $productIds null = every product not yet on Shopify */
    public function __construct(
        public ShopifyStore $store,
        public ShopifySyncLog $log,
        public ?array $productIds = null,
    ) {}

    /** Products that can be pushed (optionally limited to the given ids). */
    public static function eligibleQuery(?array $ids = null)
    {
        return Product::query()
            ->where('item_type', 'fg')
            ->where('is_active', 1)
            ->whereNull('shopify_product_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids));
    }

    public function handle(): void
    {
        Log::info("SHOPIFY PUSH STARTED — Store: {$this->store->shop_name}");
        $errors = [];

        try {
            $this->log->update(['status' => 'processing', 'error_message' => null]);

            $products = self::eligibleQuery($this->productIds)
                ->with(['variations.attributeValues.attribute', 'images', 'category'])
                ->orderBy('id')->get();

            // selected products that are not eligible (already on Shopify, raw, inactive…)
            $skipped = $this->productIds !== null ? count(array_unique($this->productIds)) - $products->count() : 0;
            $this->log->update(['total_products' => $products->count() + $skipped, 'skipped_products' => $skipped]);

            foreach ($products as $product) {
                try {
                    $result = $this->pushOne($product);
                    $this->log->increment($result === 'linked' ? 'skipped_products' : 'synced_products');
                    if ($result === 'linked') $errors[] = "{$product->sku}: already on Shopify — linked, not duplicated";
                    Log::info("✓ push {$product->sku} ({$result})");
                } catch (\Throwable $e) {
                    $this->log->increment('failed_products');
                    $errors[] = "{$product->sku}: {$e->getMessage()}";
                    Log::error("✗ push {$product->sku}: {$e->getMessage()}");

                    // no permission → every other product will fail the same way
                    if (str_contains($e->getMessage(), 'write_products')) {
                        throw $e;
                    }
                }
            }

            $this->log->update([
                'status'        => 'completed',
                'error_message' => $errors ? Str::limit(implode(' | ', $errors), 2000) : null,
            ]);
        } catch (\Throwable $e) {
            Log::error("SHOPIFY PUSH FAILED: {$e->getMessage()}");
            $this->log->update([
                'status'        => 'failed',
                'error_message' => Str::limit(implode(' | ', array_merge([$e->getMessage()], $errors)), 2000),
            ]);
        }
    }

    /** @return string created|linked */
    private function pushOne(Product $product): string
    {
        // fresh check — another push may have linked it meanwhile
        if (Product::whereKey($product->id)->whereNotNull('shopify_product_id')->exists()) {
            return 'linked';
        }

        $input = $this->buildInput($product);

        // Same SKU already on Shopify? → link instead of creating a duplicate.
        $firstSku = $input['variants'][0]['sku'] ?? null;
        if ($firstSku && ($existingId = $this->findProductIdBySku($firstSku))) {
            $this->link($product, $existingId);
            return 'linked';
        }

        $data = $this->store->graphql(<<<'GQL'
            mutation PushProduct($input: ProductSetInput!) {
              productSet(synchronous: true, input: $input) {
                product { id }
                userErrors { field message }
              }
            }
            GQL, ['input' => $input]);

        $userErrors = $data['productSet']['userErrors'] ?? [];
        if ($userErrors) {
            throw new \RuntimeException(collect($userErrors)
                ->map(fn ($e) => trim(implode('.', (array) ($e['field'] ?? [])) . ' ' . ($e['message'] ?? '')))
                ->implode('; '));
        }

        $gid = $data['productSet']['product']['id'] ?? null;
        if (!$gid) {
            throw new \RuntimeException('Shopify did not return the new product id.');
        }

        $this->link($product, $gid);
        return 'created';
    }

    /** ProductSetInput for one product (public for tests). */
    public function buildInput(Product $product): array
    {
        $variations = $product->variations->sortBy('id')->values();

        // options = attributes used by the sizes, in attribute order (Shopify allows 3)
        $options = [];
        foreach ($variations as $v) {
            foreach ($v->attributeValues->sortBy('attribute_id') as $av) {
                $name = $av->attribute->name ?? 'Option';
                $options[$name][$av->value] = true;
            }
        }
        if (count($options) > 3) {
            throw new \RuntimeException('Shopify allows at most 3 options (e.g. Size, Color); this product uses ' . count($options) . '.');
        }

        $variants = [];
        if ($variations->isEmpty()) {
            // product without sizes → Shopify's single default variant
            $options  = ['Title' => ['Default Title' => true]];
            $variants[] = $this->variant(
                [['optionName' => 'Title', 'name' => 'Default Title']],
                (float) ($product->selling_price ?? 0),
                $product->compare_at_price,
                $product->sku,
                $product->barcode
            );
        } else {
            $bySku = !$options;   // sizes without attribute values → one option "Variant" = the SKU
            if ($bySku) $options = ['Variant' => []];

            foreach ($variations as $v) {
                $v->setRelation('product', $product);
                $values = [];
                if ($bySku) {
                    $options['Variant'][$v->sku] = true;
                    $values[] = ['optionName' => 'Variant', 'name' => $v->sku];
                } else {
                    foreach (array_keys($options) as $optName) {
                        $av    = $v->attributeValues->first(fn ($x) => ($x->attribute->name ?? 'Option') === $optName);
                        $value = $av?->value ?? 'Default';
                        $options[$optName][$value] = true;
                        $values[] = ['optionName' => $optName, 'name' => $value];
                    }
                }
                $variants[] = $this->variant($values, $v->salePrice(), $v->comparePrice(), $v->sku, $v->barcode);
            }
        }

        $productOptions = [];
        $pos = 1;
        foreach ($options as $name => $vals) {
            $productOptions[] = [
                'name'     => $name,
                'position' => $pos++,
                'values'   => array_map(fn ($val) => ['name' => (string) $val], array_keys($vals)),
            ];
        }

        $input = [
            'title'          => $product->name,
            'status'         => 'DRAFT',
            'productOptions' => $productOptions,
            'variants'       => $variants,
        ];
        if (filled($product->description)) $input['descriptionHtml'] = nl2br(e($product->description));
        if (filled($product->brand))       $input['vendor']          = $product->brand;
        if ($product->category?->name)     $input['productType']     = $product->category->name;

        $files = $this->imageFiles($product);
        if ($files) $input['files'] = $files;

        return $input;
    }

    private function variant(array $optionValues, float $price, $compare, ?string $sku, ?string $barcode): array
    {
        $v = [
            'optionValues' => $optionValues,
            'price'        => number_format($price, 2, '.', ''),
        ];
        if ($compare !== null && (float) $compare > 0) $v['compareAtPrice'] = number_format((float) $compare, 2, '.', '');
        if (filled($sku))     $v['sku']     = $sku;
        if (filled($barcode)) $v['barcode'] = $barcode;
        return $v;
    }

    /**
     * Product images as public URLs Shopify can download. Skipped when the
     * software runs on a local / private address Shopify cannot reach.
     */
    private function imageFiles(Product $product): array
    {
        $files = [];
        foreach ($product->images as $img) {
            $url  = asset('storage/' . ltrim($img->image_path, '/'));
            $host = parse_url($url, PHP_URL_HOST) ?: '';
            $private = $host === '' || $host === 'localhost' || str_ends_with($host, '.test') || str_ends_with($host, '.local')
                || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
            if ($private) continue;
            $files[] = ['originalSource' => $url, 'contentType' => 'IMAGE', 'alt' => $product->name];
        }
        return $files;
    }

    private function findProductIdBySku(string $sku): ?string
    {
        $data = $this->store->graphql(<<<'GQL'
            query FindBySku($q: String!) {
              productVariants(first: 5, query: $q) { nodes { sku product { id } } }
            }
            GQL, ['q' => 'sku:"' . str_replace('"', '\\"', $sku) . '"']);

        foreach ($data['productVariants']['nodes'] ?? [] as $node) {
            if (($node['sku'] ?? null) === $sku) return $node['product']['id'] ?? null;   // exact match only
        }
        return null;
    }

    private function link(Product $product, string $gid): void
    {
        $numericId = Str::afterLast($gid, '/');   // gid://shopify/Product/123 → 123 (same as the import uses)
        Product::whereKey($product->id)->update([
            'shopify_store_id'   => $this->store->id,
            'shopify_product_id' => $numericId,
        ]);
    }
}
