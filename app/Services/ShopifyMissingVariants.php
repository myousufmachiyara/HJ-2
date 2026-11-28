<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ShopifyStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sizes / SKUs that exist in the software but not on the product's Shopify page
 * (typical after importing from LAAM, which had sizes Shopify never got).
 *
 * A size counts as already there when its SKU or barcode is on that Shopify product, or when
 * Shopify already has a size with the same option values. Only the missing ones are added;
 * nothing that exists on Shopify is changed.
 */
class ShopifyMissingVariants
{
    public function __construct(private ShopifyStore $store) {}

    /**
     * @param  Collection<Product> $products  linked products, with variations.attributeValues.attribute loaded
     * @return array<int,array{product:Product, shopify_id:string, missing:array<int,array{variation:ProductVariation,values:array}>, same_size_other_sku:int, note:?string}>
     */
    public function scan(Collection $products): array
    {
        $out = [];
        foreach ($products->chunk(15) as $chunk) {
            $gids = $chunk->map(fn ($p) => 'gid://shopify/Product/' . $p->shopify_product_id)->values()->all();
            $data = $this->store->graphql(<<<'GQL'
                query Variants($ids: [ID!]!) {
                  nodes(ids: $ids) {
                    ... on Product {
                      id
                      options { name }
                      variants(first: 250) {
                        pageInfo { hasNextPage }
                        nodes { id sku barcode selectedOptions { name value } }
                      }
                    }
                  }
                }
                GQL, ['ids' => $gids]);

            $byId = [];
            foreach ($data['nodes'] ?? [] as $n) {
                if ($n && !empty($n['id'])) $byId[Str::afterLast($n['id'], '/')] = $n;
            }

            foreach ($chunk as $p) {
                $out[$p->id] = $this->compare($p, $byId[(string) $p->shopify_product_id] ?? null);
            }
        }
        return $out;
    }

    private function compare(Product $p, ?array $shop): array
    {
        $row = ['product' => $p, 'shopify_id' => (string) $p->shopify_product_id, 'missing' => [], 'same_size_other_sku' => 0, 'note' => null];

        if (!$shop) { $row['note'] = 'Not found on Shopify (deleted there?).'; return $row; }

        $optionNames = array_map(fn ($o) => $o['name'], $shop['options'] ?? []);
        if (!$optionNames || $optionNames === ['Title']) {
            $row['note'] = 'Shopify has only a default variant (no sizes). Add the Size option on Shopify first.';
            return $row;
        }
        if ($shop['variants']['pageInfo']['hasNextPage'] ?? false) {
            $row['note'] = 'Too many sizes on Shopify to compare safely — skipped.';
            return $row;
        }

        $codes = [];
        $combos = [];
        foreach ($shop['variants']['nodes'] ?? [] as $v) {
            foreach ([$v['sku'] ?? '', $v['barcode'] ?? ''] as $c) if (trim((string) $c) !== '') $codes[ShopifyPushPlan::key($c)] = true;
            $vals = [];
            foreach ($optionNames as $name) {
                $vals[] = $this->norm(collect($v['selectedOptions'] ?? [])->firstWhere('name', $name)['value'] ?? '');
            }
            $combos[implode('|', $vals)] = true;
        }

        foreach ($p->variations->sortBy('id') as $v) {
            $v->setRelation('product', $p);
            $values = $this->valuesFor($v, $optionNames);
            if ($values === null) {
                $row['note'] = "Size {$v->sku}: its attributes do not match Shopify's options (" . implode(', ', $optionNames) . ') — not added.';
                continue;
            }
            $hasCode = ($v->sku && isset($codes[ShopifyPushPlan::key($v->sku)])) || ($v->barcode && isset($codes[ShopifyPushPlan::key($v->barcode)]));
            if ($hasCode) continue;
            if (isset($combos[implode('|', array_map(fn ($x) => $this->norm($x), $values))])) { $row['same_size_other_sku']++; continue; }
            $row['missing'][] = ['variation' => $v, 'values' => $values];
        }
        return $row;
    }

    /** [optionName => value] for a size, in Shopify's option order; null when it cannot be mapped. */
    private function valuesFor(ProductVariation $v, array $optionNames): ?array
    {
        $out = [];
        $attrs = $v->attributeValues;
        $used = 0;
        foreach ($optionNames as $name) {
            if (strcasecmp($name, 'Variant') === 0 && count($optionNames) === 1 && $attrs->isEmpty()) { $out[$name] = (string) $v->sku; continue; }
            $av = $attrs->first(fn ($x) => strcasecmp($x->attribute->name ?? '', $name) === 0);
            if (!$av) return null;
            $out[$name] = (string) $av->value;
            $used++;
        }
        return ($used === $attrs->count() || $attrs->isEmpty()) ? $out : null;
    }

    private function norm(string $s): string
    {
        return mb_strtolower(trim($s));
    }

    /**
     * Add the chosen missing sizes of one product to Shopify.
     * @param int[] $variationIds
     * @return array{created:int, errors:string[]}
     */
    public function push(Product $p, array $variationIds): array
    {
        $row = $this->scan(collect([$p]))[$p->id];
        if ($row['note'] && !$row['missing']) return ['created' => 0, 'errors' => ["{$p->sku}: {$row['note']}"]];

        $todo = array_values(array_filter($row['missing'], fn ($m) => in_array($m['variation']->id, $variationIds, true)));
        $created = 0;
        $errors = [];

        foreach (array_chunk($todo, 100) as $batch) {
            $variants = array_map(function ($m) {
                $v = $m['variation'];
                $in = [
                    'optionValues' => collect($m['values'])->map(fn ($val, $name) => ['optionName' => $name, 'name' => $val])->values()->all(),
                    'price'        => number_format($v->salePrice(), 2, '.', ''),
                    'inventoryItem' => array_filter(['sku' => $v->sku ?: null, 'tracked' => true], fn ($x) => $x !== null),
                ];
                if (($c = $v->comparePrice()) !== null) $in['compareAtPrice'] = number_format($c, 2, '.', '');
                if (filled($v->barcode)) $in['barcode'] = $v->barcode;
                return $in;
            }, $batch);

            $data = $this->store->graphql(<<<'GQL'
                mutation AddSizes($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
                  productVariantsBulkCreate(productId: $productId, variants: $variants) {
                    productVariants { id sku }
                    userErrors { field message }
                  }
                }
                GQL, ['productId' => 'gid://shopify/Product/' . $p->shopify_product_id, 'variants' => $variants]);

            $res = $data['productVariantsBulkCreate'] ?? [];
            if (!empty($res['userErrors'])) {
                $errors[] = "{$p->sku}: " . collect($res['userErrors'])->pluck('message')->filter()->implode('; ');
                continue;
            }
            foreach ($res['productVariants'] ?? [] as $node) {
                $created++;
                if (!empty($node['sku'])) {
                    ProductVariation::where('product_id', $p->id)->where('sku', $node['sku'])
                        ->update(['shopify_variant_id' => Str::afterLast($node['id'], '/')]);
                }
            }
        }
        return ['created' => $created, 'errors' => $errors];
    }
}
