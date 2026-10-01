<?php

namespace App\Http\Controllers;

use App\Imports\RawRowsImport;
use App\Services\ProductLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bulk item-import for Purchase Return, Sale Invoice, Sale Return,
 * Stock Movement and Stock Adjustment.
 *
 * Reads an uploaded sheet, resolves each row's item code (barcode or SKU,
 * variation or product) and returns a normalized JSON list for the form to
 * drop into its table. Nothing is saved here — the user reviews and saves.
 *
 * Columns are matched by header text (so column order / extra columns don't
 * matter); if headers aren't recognised the documented column order is used.
 */
class ItemsImportController extends Controller
{
    /** header keywords → field */
    private const HEADER_MAP = [
        'barcode'  => ['item code', 'code', 'barcode', 'sku', 'item'],
        'quantity' => ['quantity', 'qty', 'counted', 'count'],
        'unit_id'  => ['unit id', 'unit'],
        'price'    => ['price', 'rate', 'sale price', 'unit price', 'cost', 'unit cost'],
        'discount' => ['discount', 'disc', 'discount %', 'disc %'],
        'discount_amount' => ['discount rs', 'disc rs', 'discount amount', 'disc amount', 'discount rs/pc', 'disc rs/pc'],
    ];

    public function __construct(protected ProductLookupService $lookup)
    {
    }

    // Item Code | Quantity | Unit ID | Price
    public function purchaseReturn(Request $request): JsonResponse
    {
        return $this->handle($request, ['barcode', 'quantity', 'unit_id', 'price'], 'cost');
    }

    // Item Code | Quantity | Price | Discount % | Discount Rs (per piece)
    public function saleInvoice(Request $request): JsonResponse
    {
        return $this->handle($request, ['barcode', 'quantity', 'price', 'discount', 'discount_amount'], 'selling');
    }

    // Item Code | Quantity | Price
    public function saleReturn(Request $request): JsonResponse
    {
        return $this->handle($request, ['barcode', 'quantity', 'price'], 'selling');
    }

    // Item Code | Quantity
    public function stockTransfer(Request $request): JsonResponse
    {
        return $this->handle($request, ['barcode', 'quantity'], 'cost');
    }

    // Item Code | Quantity | Unit Cost
    public function stockAdjustment(Request $request): JsonResponse
    {
        return $this->handle($request, ['barcode', 'quantity', 'price'], 'none'); // blank cost = system cost
    }

    /**
     * @param string[] $columns   default column order (used when headers aren't recognised)
     * @param string   $priceType 'selling' or 'cost' — fallback price when the sheet has none
     */
    protected function handle(Request $request, array $columns, string $priceType): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt',
        ]);

        $import = new RawRowsImport();
        Excel::import($import, $request->file('file'));
        $rows = $import->getRows();

        if ($rows->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No item rows found in the uploaded file.'], 422);
        }

        $index = $this->mapColumns($import->getHeader(), $columns);

        $items  = [];
        $errors = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // 1-indexed + header row
            $raw    = array_values($row->toArray());
            $get    = fn ($field) => isset($index[$field]) ? ($raw[$index[$field]] ?? null) : null;

            $code = trim((string) ($get('barcode') ?? ''));
            if ($code === '') {
                continue; // blank row
            }

            $found = $this->lookup->resolve($code);
            if (!$found['success']) {
                $errors[] = "Row {$rowNum}: {$found['message']}";
                continue;
            }

            $qty = (float) ($get('quantity') ?? 0);
            if ($qty == 0.0) {
                $errors[] = "Row {$rowNum}: quantity missing for '{$code}'";
                continue;
            }

            $unitId   = ($u = $get('unit_id')) !== null && $u !== '' && is_numeric($u) ? (int) $u : null;
            $price    = ($p = $get('price')) !== null && $p !== '' && is_numeric($p) ? (float) $p : null;
            $discount = ($d = $get('discount')) !== null && $d !== '' && is_numeric($d) ? (float) $d : null;
            $discAmt  = ($da = $get('discount_amount')) !== null && $da !== '' && is_numeric($da) ? (float) $da : null;

            $src = $found['type'] === 'variation' ? $found['variation'] : $found['product'];
            $defaultPrice = match ($priceType) {
                'selling' => $src['selling_price'],
                'cost'    => $src['cost_price'],
                default   => null,
            };

            $items[] = [
                'product_id'   => $found['type'] === 'variation' ? $src['product_id'] : $src['id'],
                'variation_id' => $found['type'] === 'variation' ? $src['id'] : null,
                'name'         => $src['name'],
                'sku'          => $src['sku'],
                'barcode'      => $src['barcode'],
                'unit_id'      => $unitId ?? $src['unit_id'],
                'price'        => $price ?? $defaultPrice,
                'quantity'     => $qty,
                'discount'     => $discount,
                'discount_amount' => $discAmt,
            ];
        }

        return response()->json([
            'success' => true,
            'items'   => $items,
            'errors'  => $errors,
        ]);
    }

    /** field => column index */
    private function mapColumns(array $header, array $defaults): array
    {
        $index = [];
        foreach ($header as $col => $text) {
            $t = strtolower(preg_replace('/\s*\(.*\)\s*/', '', $text)); // "Item Code (Barcode or SKU)" → "item code"
            $t = trim(str_replace(['%', '_'], ['', ' '], $t));
            foreach (self::HEADER_MAP as $field => $words) {
                if (!in_array($field, $defaults, true) || isset($index[$field])) continue;
                if (in_array($t, $words, true)) {
                    $index[$field] = $col;
                    continue 2;
                }
            }
        }

        // Headers not recognised → documented column order
        if (!isset($index['barcode']) || !isset($index['quantity'])) {
            return array_flip($defaults);
        }
        return $index;
    }
}
