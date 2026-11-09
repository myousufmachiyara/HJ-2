<?php

namespace App\Services;

use App\Models\LaamInvoiceItem;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;

/**
 * Reads the LAAM weekly "Ledgers" export exactly as LAAM gives it (xlsx / xls / csv).
 *
 * Layout of the export:
 *   row 1  headers   Product | Product SKU | Variant Barcode | Platform | Brand | Order Number |
 *                    Shopify Order Number | Order Placement Date | Destination | Order Payment Method |
 *                    Item Status | Actual Price | Retail Price | Realized Sales | Coupon Code |
 *                    Platform Fee % | Gross Platform Fee | Gross Platform Fee | PRA Tax | Sales Tax |
 *                    Income Tax | Seller Payable | Item Id | Realization Time | Notes
 *   row 2  sub-headers under the two "Gross Platform Fee" columns:  "Seller discounts" | "Net Platform Fee"
 *   row 3+ one row per item (Item Id)
 *
 * Columns are found by header text, so a different column order or an extra column still works.
 * The two "Gross Platform Fee" columns are told apart by their sub-header (or by position
 * when the sub-header row is missing: discounts first, net fee second).
 */
class LaamSheetReader
{
    public function __construct(protected ItemCodeLookup $lookup) {}

    /** @return array{rows: array, skipped: array, errors: array, totals: array, period: array} */
    public function read(UploadedFile|string $file, ?int $ignoreInvoiceId = null): array
    {
        // string = path of a file already kept on the 'local' disk (see LaamInvoiceController::parse)
        $reader = new class implements ToArray, WithCalculatedFormulas {
            public function array(array $array) {}
        };
        $sheet = (is_string($file) ? Excel::toArray($reader, $file, 'local') : Excel::toArray($reader, $file))[0] ?? [];

        $existing = LaamInvoiceItem::query()
            ->when($ignoreInvoiceId, fn ($q) => $q->where('laam_invoice_id', '!=', $ignoreInvoiceId))
            ->pluck('item_id')->flip()->all();

        return $this->parseSheet($sheet, $existing);
    }

    /**
     * @param array $sheet    rows of the first sheet (row 0 = header row of the export)
     * @param array $existing Item Ids already imported, as [itemId => anything]
     */
    public function parseSheet(array $sheet, array $existing = []): array
    {

        // header row = first row that has "Product SKU"
        $headerAt = null;
        foreach ($sheet as $i => $r) {
            if (collect($r)->contains(fn ($v) => $this->norm($v) === 'product sku')) { $headerAt = $i; break; }
        }
        if ($headerAt === null) {
            return $this->fail('This does not look like a LAAM ledger export (no "Product SKU" column found).');
        }

        $header = array_map(fn ($h) => $this->norm($h), $sheet[$headerAt]);
        $sub    = array_map(fn ($h) => $this->norm($h), $sheet[$headerAt + 1] ?? []);
        $col    = $this->mapColumns($header, $sub);

        foreach (['sku', 'realized', 'seller_payable', 'item_id'] as $need) {
            if (!isset($col[$need])) {
                return $this->fail("Column for '{$need}' not found in the file.");
            }
        }

        // data starts after the sub-header row, when there is one
        $start = $headerAt + 1;
        if (collect($sub)->contains(fn ($v) => in_array($v, ['seller discounts', 'net platform fee'], true))) $start++;

        $rows = $skipped = $errors = [];
        $seen = [];

        foreach (array_slice($sheet, $start, null, true) as $i => $r) {
            $n = $i + 1; // real sheet row number
            $get = fn ($k) => isset($col[$k]) ? ($r[$col[$k]] ?? null) : null;

            $itemId = trim((string) $get('item_id'));
            $sku    = trim((string) $get('sku'));
            if ($itemId === '' && $sku === '') continue;                       // blank / total row
            if (is_numeric($itemId) && str_contains($itemId, '.')) $itemId = (string) (int) $itemId;

            if ($itemId === '') { $errors[] = "Row {$n}: Item Id is missing."; continue; }

            $status = strtoupper(trim((string) $get('status')));
            if ($status !== '' && $status !== 'SOLD') {
                $skipped[] = ['row' => $n, 'item_id' => $itemId, 'sku' => $sku, 'status' => $status,
                              'reason' => "Item Status is {$status} (only SOLD rows are posted)"];
                continue;
            }

            if (isset($seen[$itemId]))   { $errors[] = "Row {$n}: Item Id {$itemId} appears twice in this file."; continue; }
            $seen[$itemId] = true;

            $row = [
                'row'            => $n,
                'item_id'        => $itemId,
                'sku'            => $sku,
                'name'           => trim((string) $get('product')),
                'platform'       => trim((string) $get('platform')),
                'brand'          => trim((string) $get('brand')),
                'order_number'   => trim((string) $get('order_number')),
                'shopify_order'  => trim((string) $get('shopify_order')),
                'order_date'     => $this->date($get('order_date')),
                'destination'    => trim((string) $get('destination')),
                'payment_method' => trim((string) $get('payment_method')),
                'status'         => $status ?: 'SOLD',
                'actual_price'   => $this->num($get('actual')),
                'retail_price'   => $this->num($get('retail')),
                'realized'       => $this->num($get('realized')),
                'coupon_code'    => trim((string) $get('coupon')),
                'fee_pct'        => $this->num($get('fee_pct')),
                'seller_discount'=> $this->num($get('seller_discount')),
                'net_fee'        => $this->num($get('net_fee')),
                'pra_tax'        => $this->num($get('pra')),
                'sales_tax'      => $this->num($get('sales_tax')),
                'income_tax'     => $this->num($get('income_tax')),
                'seller_payable' => $this->num($get('seller_payable')),
                'realization'    => $this->date($get('realization')),
                'notes'          => trim((string) $get('notes')),
                'quantity'       => 1,                                         // one row = one piece (own Item Id)
            ];

            // product match: SKU first, then variant barcode
            $found = $this->lookup->resolve($sku);
            if (!$found['success'] && ($bc = trim((string) $get('barcode'))) !== '') $found = $this->lookup->resolve($bc);

            if ($found['success']) {
                $src = $found['type'] === 'variation' ? $found['variation'] : $found['product'];
                $row['product_id']   = $found['type'] === 'variation' ? $src['product_id'] : $src['id'];
                $row['variation_id'] = $found['type'] === 'variation' ? $src['id'] : null;
                $row['matched_sku']  = $src['sku'];
                $row['matched_name'] = $src['name'];
            } else {
                $row['product_id'] = $row['variation_id'] = null;
                $row['match_error'] = $found['message'];
            }

            // what LAAM's own formula leaves vs. the Seller Payable it printed
            $calc = $row['realized'] - $row['net_fee'] - $row['pra_tax'] - $row['sales_tax'] - $row['income_tax'];
            $row['adjustment'] = round($row['seller_payable'] - $calc, 2);      // seller discounts / coupons / rounding

            $row['duplicate'] = isset($existing[$itemId]);
            $rows[] = $row;
        }

        // report period = first / last realization date (falls back to order dates)
        $dates  = collect($rows)->pluck('realization')->filter()->sort()->values();
        if (!$dates->first()) $dates = collect($rows)->pluck('order_date')->filter()->sort()->values();
        $totals = [
            'count'      => count($rows),
            'retail'     => round(collect($rows)->sum('retail_price'), 2),
            'realized'   => round(collect($rows)->sum('realized'), 2),
            'fee'        => round(collect($rows)->sum('net_fee'), 2),
            'pra_tax'    => round(collect($rows)->sum('pra_tax'), 2),
            'sales_tax'  => round(collect($rows)->sum('sales_tax'), 2),
            'income_tax' => round(collect($rows)->sum('income_tax'), 2),
            'adjustment' => round(collect($rows)->sum('adjustment'), 2),
            'payable'    => round(collect($rows)->sum('seller_payable'), 2),
        ];

        return [
            'rows' => $rows, 'skipped' => $skipped, 'errors' => $errors, 'totals' => $totals,
            'period' => ['from' => $dates->first(), 'to' => $dates->last()],
        ];
    }

    // ─────────────────────────────────────────────────────────────

    private function fail(string $msg): array
    {
        return ['rows' => [], 'skipped' => [], 'errors' => [$msg], 'totals' => [], 'period' => []];
    }

    private function norm($v): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', str_replace("\xEF\xBB\xBF", '', (string) $v))));
    }

    /** field → column index */
    private function mapColumns(array $h, array $sub): array
    {
        $names = [
            'product' => ['product'], 'sku' => ['product sku', 'sku'], 'barcode' => ['variant barcode', 'barcode'],
            'platform' => ['platform'], 'brand' => ['brand'], 'order_number' => ['order number'],
            'shopify_order' => ['shopify order number'], 'order_date' => ['order placement date'],
            'destination' => ['destination'], 'payment_method' => ['order payment method'],
            'status' => ['item status'], 'actual' => ['actual price'], 'retail' => ['retail price'],
            'realized' => ['realized sales'], 'coupon' => ['coupon code'], 'fee_pct' => ['platform fee %', 'platform fee'],
            'pra' => ['pra tax'], 'sales_tax' => ['sales tax'], 'income_tax' => ['income tax'],
            'seller_payable' => ['seller payable'], 'item_id' => ['item id'],
            'realization' => ['realization time', 'realization date'], 'notes' => ['notes'],
        ];

        $col = [];
        foreach ($h as $i => $text) {
            foreach ($names as $field => $words) {
                if (!isset($col[$field]) && in_array($text, $words, true)) { $col[$field] = $i; break; }
            }
        }

        // the two "Gross Platform Fee" columns
        $gross = array_keys(array_filter($h, fn ($t) => $t === 'gross platform fee'));
        foreach ($gross as $pos => $i) {
            $s = $sub[$i] ?? '';
            if ($s === 'seller discounts')      $col['seller_discount'] = $i;
            elseif ($s === 'net platform fee')  $col['net_fee'] = $i;
            elseif ($pos === 0 && !isset($col['seller_discount'])) $col['seller_discount'] = $i;
            elseif ($pos === 1 && !isset($col['net_fee']))         $col['net_fee'] = $i;
        }
        foreach ($h as $i => $t) {
            if ($t === 'net platform fee' && !isset($col['net_fee'])) $col['net_fee'] = $i;
            if ($t === 'seller discounts' && !isset($col['seller_discount'])) $col['seller_discount'] = $i;
        }
        return $col;
    }

    private function num($v): float
    {
        if ($v === null || $v === '') return 0.0;
        if (is_numeric($v)) return round((float) $v, 2);
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $v);
        return $clean === '' || $clean === '-' ? 0.0 : round((float) $clean, 2);
    }

    /** "27-Oct-26", "2026-10-27" or an Excel serial number → Y-m-d */
    private function date($v): ?string
    {
        if ($v === null || trim((string) $v) === '') return null;
        try {
            if (is_numeric($v) && (float) $v > 20000) {
                return XlDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
            }
            $s = trim((string) $v);
            foreach (['d-M-y', 'd-M-Y', 'Y-m-d', 'd/m/Y', 'd/m/y'] as $f) {
                $d = \DateTimeImmutable::createFromFormat('!' . $f, $s);
                if ($d && $d->format($f) === $s) return $d->format('Y-m-d');
            }
            return (new \DateTimeImmutable($s))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
