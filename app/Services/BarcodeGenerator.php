<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariation;

/**
 * Barcodes for NEW products / sizes: a random, unique 12-digit number — never a copy of the SKU.
 *
 *  - digits only → Code 128 packs two digits per symbol, so it prints with thick bars and scans
 *    reliably on the 2in label, however long the SKU is;
 *  - first digit is never 0, so Excel / CSV round-trips can't strip a leading zero;
 *  - checked against every barcode AND SKU (deleted rows included), so it can never clash with an
 *    existing code or be mistaken for a SKU when scanned.
 * Existing barcodes are never changed.
 */
class BarcodeGenerator
{
    public static function generate(): string
    {
        for ($try = 0; $try < 50; $try++) {
            $code = (string) random_int(1, 9);
            for ($i = 0; $i < 11; $i++) $code .= random_int(0, 9);

            if (!self::taken($code)) return $code;
        }
        throw new \RuntimeException('Could not generate a unique barcode.');
    }

    public static function taken(string $code): bool
    {
        return ProductVariation::withTrashed()->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))->exists()
            || Product::withTrashed()->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))->exists();
    }
}
