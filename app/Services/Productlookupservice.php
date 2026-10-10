<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariation;

/**
 * Resolves an item code into a product or product-variation.
 * Accepts (in this order): variation barcode, variation SKU,
 * product barcode, product SKU. Used by all bulk Excel imports.
 */
class ProductLookupService
{
    public function resolve(string $code): array
    {
        $code = trim($code);

        if ($code === '') {
            return ['success' => false, 'message' => 'Empty item code'];
        }

        $variation = ProductVariation::with('product')->where('barcode', $code)->first()
            ?? ProductVariation::with('product')->where('sku', $code)->first();

        if ($variation && $variation->product) {
            $p = $variation->product;
            return [
                'success'   => true,
                'type'      => 'variation',
                'variation' => [
                    'id'            => $variation->id,
                    'product_id'    => $variation->product_id,
                    'name'          => $p->name,
                    'sku'           => $variation->sku,
                    'barcode'       => $variation->barcode,
                    'price'         => (float) ($p->cost_price ?? 0),
                    'cost_price'    => (float) ($p->cost_price ?? 0),
                    'selling_price' => $variation->salePrice(),
                    'compare_at_price' => $variation->comparePrice(),
                    'unit_id'       => $p->measurement_unit,
                ],
            ];
        }

        $product = Product::where('barcode', $code)->first()
            ?? Product::where('sku', $code)->first();

        if ($product) {
            return [
                'success' => true,
                'type'    => 'product',
                'product' => [
                    'id'            => $product->id,
                    'name'          => $product->name,
                    'sku'           => $product->sku,
                    'barcode'       => $product->barcode,
                    'cost_price'    => (float) ($product->cost_price ?? 0),
                    'selling_price' => (float) ($product->selling_price ?? 0),
                    'unit_id'       => $product->measurement_unit,
                ],
            ];
        }

        return ['success' => false, 'message' => "No product found for code '{$code}'"];
    }
}
