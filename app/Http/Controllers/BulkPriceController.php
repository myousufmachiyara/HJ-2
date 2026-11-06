<?php

namespace App\Http\Controllers;

use App\Exports\VariantPriceExport;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use App\Models\SaleInvoiceItem;
use App\Models\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bulk selling-price update.
 *
 *  1. pick a location, filter by stock / best seller / least sold
 *  2. type new prices (or apply a %, amount or fixed price to the ticked rows)
 *  3. "Save & Export" saves the prices and downloads the Excel sheet:
 *        Variant SKU | Variant Price | Variant Discount | Sell Ahead (blank)
 */
class BulkPriceController extends Controller
{
    /**
     * What "actual price" means in this project:
     *
     *   true  → Variant Price    = Compare-at price (the price BEFORE discount)
     *           Variant Discount = Selling price    (the discounted price)
     *           If an item has no compare-at price, its selling price is the
     *           actual price and Variant Discount stays blank.
     *
     *   false → Variant Price    = Selling price
     *           Variant Discount = Compare-at price
     *
     * Flip this one constant if the sheet should be the other way round.
     */
    public const ACTUAL_IS_COMPARE_AT = true;

    // ─────────────────────────────────────────────────────────────
    // Page
    // ─────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $locations  = Location::grouped();
        $categories = ProductCategory::orderBy('name')->get(['id', 'name']);
        $rows       = collect();

        if ($request->filled('location_id')) {
            $rows = $this->rows($request);
        }

        return view('products.bulk-price', [
            'locations'  => $locations,
            'categories' => $categories,
            'rows'       => $rows,
            'actualIsCompareAt' => self::ACTUAL_IS_COMPARE_AT,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Save prices + download Excel
    // ─────────────────────────────────────────────────────────────

    public function updateAndExport(Request $request)
    {
        // The page sends only the ticked rows as one JSON field, so big
        // selections never hit PHP's max_input_vars limit.
        $items = json_decode((string) $request->input('payload'), true);

        if (!is_array($items) || !count($items)) {
            return back()->with('error', 'Please tick at least one product.');
        }

        $items = array_values(array_filter($items, fn ($i) => !empty($i['product_id'])));

        foreach ($items as $i) {
            if (isset($i['new_price']) && $i['new_price'] !== '' && (!is_numeric($i['new_price']) || $i['new_price'] < 0)) {
                return back()->with('error', 'Invalid price entered for one of the products.');
            }
        }

        $sheet = [];

        DB::transaction(function () use ($items, &$sheet) {
            foreach ($items as $i) {
                $product = Product::find($i['product_id']);
                if (!$product) continue;

                $variation = !empty($i['variation_id']) ? ProductVariation::find($i['variation_id']) : null;
                if (!empty($i['variation_id']) && !$variation) continue;

                // 1. save the new ACTUAL price (never the discount price)
                if (isset($i['new_price']) && $i['new_price'] !== '') {
                    $column = $this->prices($product, $variation)['writes'];
                    ($variation ?: $product)->update([$column => round((float) $i['new_price'], 2)]);
                    $product->refresh();
                    $variation?->refresh();
                }

                // 2. export row with the values as they are now saved
                $p = $this->prices($product, $variation);
                $sheet[] = [
                    $variation?->sku ?? $product->sku,
                    $p['actual'],
                    $p['discount'],
                    '',                       // Sell Ahead stays empty
                ];
            }
        });

        return Excel::download(
            new VariantPriceExport($sheet),
            'variant-price-update-' . now()->format('Ymd-His') . '.xlsx'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /** Actual / discount prices of an item (variation first, product as fallback). */
    private function prices(Product $product, ?ProductVariation $v): array
    {
        $selling = $v?->selling_price ?? $product->selling_price;
        $compare = $v?->compare_at_price ?? $product->compare_at_price;

        $selling = $selling !== null ? (float) $selling : null;
        $compare = ($compare !== null && (float) $compare > 0) ? (float) $compare : null;

        if (self::ACTUAL_IS_COMPARE_AT) {
            return [
                'actual'   => $compare ?? $selling,
                'discount' => $compare !== null ? $selling : null,
                'writes'   => $compare !== null ? 'compare_at_price' : 'selling_price',
            ];
        }

        return [
            'actual'   => $selling,
            'discount' => $compare,
            'writes'   => 'selling_price',
        ];
    }

    /** One row per product / variation sitting at the chosen location, filtered + sorted. */
    private function rows(Request $r)
    {
        $locationId = (int) $r->location_id;

        // Stock at the location — the stock ledger is the source of truth
        $stock = StockLedger::where('location_id', $locationId)
            ->groupBy('product_id', 'variation_id')
            ->selectRaw('product_id, variation_id, ROUND(SUM(qty), 3) AS stock_qty')
            ->get();

        // Units sold per item (this location or all, optional date range)
        $sold = SaleInvoiceItem::query()
            ->join('sale_invoices as si', 'si.id', '=', 'sale_invoice_items.sale_invoice_id')
            ->whereNull('si.deleted_at')
            ->when($r->input('sold_scope') !== 'all', fn ($q) => $q->where('si.location_id', $locationId))
            ->when($r->filled('date_from'), fn ($q) => $q->whereDate('si.date', '>=', $r->date_from))
            ->when($r->filled('date_to'),   fn ($q) => $q->whereDate('si.date', '<=', $r->date_to))
            ->groupBy('sale_invoice_items.product_id', 'sale_invoice_items.variation_id')
            ->selectRaw('sale_invoice_items.product_id, sale_invoice_items.variation_id, SUM(sale_invoice_items.quantity) AS sold')
            ->get()
            ->keyBy(fn ($x) => $x->product_id . '-' . ($x->variation_id ?: 0));

        $products   = Product::with('category')->whereIn('id', $stock->pluck('product_id')->unique())->get()->keyBy('id');
        $variations = ProductVariation::whereIn('id', $stock->pluck('variation_id')->filter()->unique())->get()->keyBy('id');

        $itemType   = $r->input('item_type', 'fg');
        $categoryId = $r->input('category_id');

        $rows = $stock->map(function ($s) use ($products, $variations, $sold, $itemType, $categoryId) {
            $p = $products[$s->product_id] ?? null;
            if (!$p) return null;                                   // deleted product

            $v = $s->variation_id ? ($variations[$s->variation_id] ?? null) : null;
            if ($s->variation_id && !$v) return null;               // deleted variation

            if ($itemType === 'fg' && !in_array($p->item_type, ['fg', null, ''], true)) return null;
            if ($categoryId && (int) $p->category_id !== (int) $categoryId) return null;

            $price = $this->prices($p, $v);

            return (object) [
                'product_id'   => $p->id,
                'variation_id' => $v?->id,
                'sku'          => $v?->sku ?? $p->sku,
                'name'         => $p->name,
                'category'     => $p->category?->name,
                'stock'        => (float) $s->stock_qty,
                'sold'         => (float) ($sold[$s->product_id . '-' . ($s->variation_id ?: 0)]->sold ?? 0),
                'actual'       => $price['actual'],
                'discount'     => $price['discount'],
            ];
        })->filter();

        // Available-stock filter (default: only what is actually in stock)
        $min = $r->filled('stock_min') ? (float) $r->stock_min : null;
        $max = $r->filled('stock_max') ? (float) $r->stock_max : null;
        $rows = $rows->filter(fn ($x) => ($min !== null ? $x->stock >= $min : $x->stock > 0)
                                      && ($max === null || $x->stock <= $max));

        // Best seller / least sold
        $rows = match ($r->input('sort')) {
            'best_seller' => $rows->sortByDesc('sold'),
            'least_sold'  => $rows->sortBy('sold'),
            default       => $rows->sortBy('sku'),
        };

        if ($r->filled('limit')) {
            $rows = $rows->take((int) $r->limit);
        }

        return $rows->values();
    }
}
