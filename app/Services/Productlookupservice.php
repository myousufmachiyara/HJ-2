<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariation;

/**
 * Resolves a barcode/item-code into a product or product-variation.
 *
 * IMPORTANT: This almost certainly duplicates logic you already have in
 * whatever controller serves GET /get-product-by-code/{code}. Ideally,
 * move that controller's logic in here and have BOTH the live-scan
 * endpoint and this bulk-import flow call resolve(), so the two paths
 * can never drift apart. Adjust model names / column names below to
 * match your actual schema if they differ.
 */
class ProductLookupService
{
    public function resolve(string $code): array
    {
        $code = trim($code);

        if ($code === '') {
            return ['success' => false, 'message' => 'Empty item code'];
        }

        // 1) Variation-level barcode first
        $variation = ProductVariation::with('product')->where('barcode', $code)->first();
        if ($variation) {
            return [
                'success' => true,
                'type' => 'variation',
                'variation' => [
                    'id'         => $variation->id,
                    'product_id' => $variation->product_id,
                    'sku'        => $variation->sku,
                    'barcode'    => $variation->barcode,
                    'price'      => $variation->price ?? optional($variation->product)->cost_price ?? 0,
                    'unit_id'    => optional($variation->product)->measurement_unit,
                ],
            ];
        }

        // 2) Product-level barcode
        $product = Product::where('barcode', $code)->first();
        if ($product) {
            return [
                'success' => true,
                'type' => 'product',
                'product' => [
                    'id'            => $product->id,
                    'name'          => $product->name,
                    'barcode'       => $product->barcode,
                    'cost_price'    => $product->cost_price ?? 0,
                    'selling_price' => $product->selling_price ?? 0,
                    'unit_id'       => $product->measurement_unit,
                ],
            ];
        }

        return ['success' => false, 'message' => "No product found for code '{$code}'"];
    }
}