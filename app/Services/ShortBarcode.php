<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\DB;
use Picqer\Barcode\Types\TypeCode128;

/**
 * Labels that really scan on the 2-inch label — WITHOUT changing any stored barcode or SKU.
 *
 * A 203-dpi printer on a 2in (406-dot) label can only print a Code 128 reliably with bars at least
 * 2 dots (0.25 mm) wide. That leaves room for ~183 bar-widths including the quiet zones — about 13
 * letters/digits mixed, but 30+ when digits only. A long barcode such as PS-00006-12-13Y therefore
 * prints with 1-dot bars and does not scan.
 *
 * For those items only, the LABEL carries a short numeric "label code" = 99 + the 8-digit variation id
 * (e.g. 9900001234). Billtrix understands it on every scan screen, so the stored barcode / SKU stay
 * exactly as they are on Shopify, LAAM etc. A real barcode or SKU always wins over a label code.
 */
class ShortBarcode
{
    private const LABEL_DOTS = 406;   // 2 in x 203 dpi
    private const QUIET      = 20;    // 10 bar-widths of blank on each side
    private const PREFIX     = '99';

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

    public static function labelCode(int $variationId): string
    {
        return self::PREFIX . str_pad((string) $variationId, 8, '0', STR_PAD_LEFT);
    }

    /** 9900001234 -> 1234; anything else -> null. */
    public static function decode(string $code): ?int
    {
        return preg_match('/^' . self::PREFIX . '(\d{8})$/', trim($code), $m) ? (int) $m[1] : null;
    }

    /** What to print under the bars for a label whose normal barcode is $code. */
    public static function forLabel(int $variationId, string $code): string
    {
        if (self::fits($code)) return $code;
        $short = self::labelCode($variationId);
        // never print a code that is somebody else's real barcode / SKU
        $taken = ProductVariation::withTrashed()->where('id', '!=', $variationId)->where(fn ($q) => $q->where('barcode', $short)->orWhere('sku', $short))->exists()
              || Product::withTrashed()->where(fn ($q) => $q->where('barcode', $short)->orWhere('sku', $short))->exists();
        return $taken ? $code : $short;
    }

    /** The variation a scanned label code belongs to (null when $code is not a label code). */
    public static function variationFor(string $code): ?ProductVariation
    {
        $id = self::decode($code);
        return $id ? ProductVariation::with('product')->find($id) : null;
    }

    /** How many live variations have a normal barcode too long for the label (they print a label code). */
    public static function countLong(): int
    {
        $n = 0;
        ProductVariation::query()
            ->join('products', 'products.id', '=', 'product_variations.product_id')
            ->whereNull('products.deleted_at')
            ->whereRaw('LENGTH(COALESCE(product_variations.barcode, products.barcode, product_variations.sku)) >= 14')   // shorter ones always fit
            ->select([DB::raw('COALESCE(product_variations.barcode, products.barcode, product_variations.sku) AS code')])
            ->orderBy('product_variations.id')
            ->chunk(2000, function ($rows) use (&$n) {
                foreach ($rows as $r) if (!self::fits((string) $r->code)) $n++;
            });
        return $n;
    }
}
