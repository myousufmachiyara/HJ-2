<?php

namespace App\Http\Controllers;

use App\Imports\RawRowsImport;
use App\Services\ProductLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bulk item-import for Purchase Return, Sale Invoice, Sale Return and
 * Stock Transfer. (Purchase Invoice keeps its existing client-side
 * SheetJS importer — untouched.)
 *
 * Every endpoint here does the SAME thing: read an uploaded sheet,
 * resolve each row's item code through ProductLookupService, and
 * hand back a normalized JSON list of items for the front-end to drop
 * into the on-screen table. Nothing is written to the database here —
 * the user still reviews and clicks the module's own Save button.
 */
class ItemsImportController extends Controller
{
    public function __construct(protected ProductLookupService $lookup)
    {
    }

    // Item Code | Quantity | Unit ID | Price
    public function purchaseReturn(Request $request): JsonResponse
    {
        return $this->handle($request, ['barcode', 'quantity', 'unit_id', 'price']);
    }

    // Item Code | Quantity | Unit ID | Price | Discount %
    public function saleInvoice(Request $request): JsonResponse
    {
        return $this->handle($request, ['barcode', 'quantity', 'unit_id', 'price', 'discount']);
    }

    // Item Code | Quantity | Price
    public function saleReturn(Request $request): JsonResponse
    {
        return $this->handle($request, ['barcode', 'quantity', 'price']);
    }

    // Item Code | Quantity
    public function stockTransfer(Request $request): JsonResponse
    {
        return $this->handle($request, ['barcode', 'quantity']);
    }

    /**
     * @param string[] $columns Column order expected in the uploaded sheet
     *                          (must match that module's Download Template)
     */
    protected function handle(Request $request, array $columns): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new RawRowsImport();
        Excel::import($import, $request->file('file'));
        $rows = $import->getRows();

        if ($rows->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No item rows found in the uploaded file.',
            ], 422);
        }

        $items  = [];
        $errors = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // +2: 1-indexed + header row
            $raw    = $row->toArray();
            $values = array_combine(
                $columns,
                array_pad(array_slice($raw, 0, count($columns)), count($columns), null)
            );

            $barcode = trim((string) ($values['barcode'] ?? ''));
            if ($barcode === '') {
                continue; // silently skip fully blank rows
            }

            $found = $this->lookup->resolve($barcode);
            if (! $found['success']) {
                $errors[] = "Row {$rowNum}: {$found['message']}";
                continue;
            }

            $qty      = (float) ($values['quantity'] ?? 0);
            $unitId   = isset($values['unit_id']) && $values['unit_id'] !== '' ? (int) $values['unit_id'] : null;
            $price    = isset($values['price']) && $values['price'] !== '' ? (float) $values['price'] : null;
            $discount = isset($values['discount']) && $values['discount'] !== '' ? (float) $values['discount'] : null;

            if ($found['type'] === 'variation') {
                $v = $found['variation'];
                $items[] = [
                    'product_id'   => $v['product_id'],
                    'variation_id' => $v['id'],
                    'sku'          => $v['sku'],
                    'barcode'      => $v['barcode'],
                    'unit_id'      => $unitId ?? $v['unit_id'],
                    'price'        => $price ?? $v['price'],
                    'quantity'     => $qty,
                    'discount'     => $discount,
                ];
            } else {
                $p = $found['product'];
                $items[] = [
                    'product_id'   => $p['id'],
                    'variation_id' => null,
                    'sku'          => null,
                    'barcode'      => $p['barcode'],
                    'unit_id'      => $unitId ?? $p['unit_id'],
                    'price'        => $price ?? $p['cost_price'],
                    'quantity'     => $qty,
                    'discount'     => $discount,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'items'   => $items,
            'errors'  => $errors,
        ]);
    }
}