<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\DB;
use Picqer\Barcode\Types\TypeCode128;

/**
 * Barcodes that really scan on the 2-inch label.
 *
 * A 203-dpi printer on a 2in (406-dot) label can only print a Code 128 reliably with bars at
 * least 2 dots (0.25 mm) wide. That leaves room for ~183 bar-widths including the quiet zones —
 * about 13 letters/digits mixed, but 30+ when the code is digits only (Code 128 packs two digits
 * per symbol). A long SKU such as PS-00006-12-13Y therefore prints with 1-dot bars and fails.
 *
 * This service tells whether a code fits and gives long ones a short numeric code instead.
 * SKUs are never touched — scanning a SKU still finds the item (ItemCodeLookup tries barcode, then SKU).
 */
class ShortBarcode
{
    private const LABEL_DOTS = 406;   // 2 in × 203 dpi
    private const QUIET      = 20;    // 10 bar-widths of blank on each side

    /** True when the code prints with bars of at least 2 printer dots on the label. */
    public static function fits(string $code): bool
    {
        if ($code === '') return true;
        try {
            $modules = (new TypeCode128())->getBarcode($code)->getWidth();
        } catch (\Throwable $e) {
            return false;
        }
        return ($modules + self::QUIET) * 2 <= self::LABEL_DOTS;
    }

    /** Next free 10-digit numeric code: "20" + 8-digit counter. Call inside a transaction. */
    public static function next(): string
    {
        $db  = DB::table('barcode_sequences');
        $key = 'LABEL';

        $row = (clone $db)->where('prefix', $key)->lockForUpdate()->first();
        if (!$row) {
            (clone $db)->insert(['prefix' => $key, 'next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $row = (clone $db)->where('prefix', $key)->lockForUpdate()->first();
        }

        $n = (int) $row->next_number;
        do {
            $code = '20' . str_pad((string) $n, 8, '0', STR_PAD_LEFT);
            $n++;
        } while (
            ProductVariation::withTrashed()->where('barcode', $code)->exists()
            || Product::withTrashed()->where('barcode', $code)->exists()
        );
        (clone $db)->where('prefix', $key)->update(['next_number' => $n, 'updated_at' => now()]);

        return $code;
    }

    /** Barcode for a NEW variation: its SKU when that scans well, otherwise a short numeric code. */
    public static function forNewVariation(string $sku): ?string
    {
        if (ProductVariation::withTrashed()->where('barcode', $sku)->exists()) {
            return null;
        }
        return self::fits($sku) ? $sku : self::next();
    }

    /**
     * Variations whose label barcode (own barcode, else the product's, else the SKU) is too long.
     * Returns [variation id => current code].
     */
    public static function longOnes(): array
    {
        $out = [];
        ProductVariation::query()
            ->join('products', 'products.id', '=', 'product_variations.product_id')
            ->whereNull('products.deleted_at')
            ->whereRaw('LENGTH(COALESCE(product_variations.barcode, products.barcode, product_variations.sku)) >= 14')   // shorter ones always fit
            ->select(['product_variations.id', DB::raw('COALESCE(product_variations.barcode, products.barcode, product_variations.sku) AS code')])
            ->orderBy('product_variations.id')
            ->chunk(2000, function ($rows) use (&$out) {
                foreach ($rows as $r) {
                    if (!self::fits((string) $r->code)) $out[(int) $r->id] = (string) $r->code;
                }
            });
        return $out;
    }

    /** Give every too-long variation a short numeric barcode. Returns how many were changed. */
    public static function shortenAll(): int
    {
        $ids = array_keys(self::longOnes());
        $done = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::transaction(function () use ($chunk, &$done) {
                foreach ($chunk as $id) {
                    $v = ProductVariation::find($id);
                    if (!$v) continue;
                    $v->barcode = self::next();
                    $v->save();
                    $done++;
                }
            });
        }
        return $done;
    }
}
