<?php

namespace App\Http\Controllers;

use App\Services\ShopifyPricePush;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Push Prices to Shopify:  paste SKUs / barcodes (or upload a sheet) → review → send.
 * The sheet may be the Excel from Bulk Price Update as it is (column "Variant SKU").
 */
class ShopifyPriceController extends Controller
{
    private const CODE_HEADERS = ['variant sku', 'sku', 'product sku', 'variant barcode', 'barcode', 'item code', 'code'];

    public function __construct(protected ShopifyPricePush $service) {}

    public function index()
    {
        return view('shopify.push-prices');
    }

    /** Step 1 (AJAX): codes from the box and/or file → rows to review. */
    public function check(Request $request)
    {
        $request->validate([
            'codes' => 'nullable|string|max:200000',
            'file'  => 'nullable|file|mimes:xlsx,xls,csv,txt',
        ]);

        $codes = preg_split('/[\s,;]+/', (string) $request->codes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sheet = $request->hasFile('file') ? $this->readSheet($request->file('file')) : [];
        $codes = array_merge($codes, array_column($sheet, 'code'));

        if (!$codes) {
            return response()->json(['success' => false, 'message' => 'Paste at least one SKU / barcode, or upload a file.'], 422);
        }

        $resolved = $this->service->resolveCodes($codes);
        $rows     = $this->service->describe($resolved['items']);
        $errors   = $resolved['errors'];

        // The sheet carries prices ("Variant Price" = before discount, "Variant Discount" = after discount):
        // show them next to what is saved now, so they can be saved in Billtrix and sent to Shopify together.
        $hasPrices = collect($sheet)->contains(fn ($s) => $s['price'] !== null);
        if ($hasPrices) {
            $byCode = [];
            foreach ($sheet as $s) $byCode[strtoupper($s['code'])] = $s;
            $skuToRow = [];
            foreach ($rows as $i => $r) $skuToRow[strtoupper($r['sku'])] = $i;

            foreach ($byCode as $code => $s) {
                // the sheet may give a barcode instead of the SKU: find the row through the lookup
                $i = $skuToRow[$code] ?? null;
                if ($i === null) {
                    $one = $this->service->resolveCodes([$s['code']])['items'][0] ?? null;
                    if ($one) foreach ($rows as $k => $r) if ($r['product_id'] === $one['product_id'] && $r['variation_id'] === $one['variation_id']) { $i = $k; break; }
                }
                if ($i === null) continue;
                if ($s['price'] === null || $s['price'] <= 0) { $rows[$i]['ok'] = false; $rows[$i]['note'] = 'No price in the sheet'; continue; }

                [$selling, $compare] = $this->split($s['price'], $s['discount']);
                $rows[$i]['sheet'] = true;
                $rows[$i]['new_price']   = $selling;
                $rows[$i]['new_compare'] = $compare;
                if ($compare !== null && $selling > $compare) { $rows[$i]['ok'] = false; $rows[$i]['note'] = 'Discount is higher than the price'; }
            }
        }

        return response()->json(['success' => true, 'rows' => $rows, 'errors' => $errors, 'has_prices' => $hasPrices]);
    }

    /** Sheet "Variant Price" (before discount) + "Variant Discount" (after) → [selling price, compare-at price|null]. */
    private function split(float $price, ?float $discount): array
    {
        return ($discount !== null && $discount > 0) ? [$discount, $price] : [$price, null];
    }

    /** Step 2: queue the push for the rows the user ticked. */
    public function push(Request $request)
    {
        $refs = json_decode((string) $request->input('payload'), true);
        if (!is_array($refs) || !$refs) {
            return back()->with('error', 'Tick at least one item to send.');
        }
        $refs = array_values(array_filter($refs, fn ($r) => !empty($r['product_id'])));

        // rows that came with prices from the sheet: save them in Billtrix first, then send exactly those
        $saved = 0;
        $withPrices = array_values(array_filter($refs, fn ($r) => isset($r['new_price']) && is_numeric($r['new_price'])));
        if ($withPrices) {
            try { $saved = $this->savePrices($withPrices); }
            catch (\RuntimeException $e) { return back()->with('error', $e->getMessage() . ' Nothing was saved or sent.'); }
        }

        $refs = array_map(fn ($r) => [
            'product_id'   => (int) ($r['product_id'] ?? 0),
            'variation_id' => !empty($r['variation_id']) ? (int) $r['variation_id'] : null,
        ], $refs);

        $res = $this->service->dispatch($refs);

        $savedNote = $saved ? "Prices saved in Billtrix for {$saved} item(s). " : '';

        if ($res['queued'] === 0) {
            return back()->with('error', $savedNote . 'Nothing was sent — none of the items is on a connected Shopify store.');
        }

        return redirect()->route('shopify.settings')->with('success', $savedNote .
            "Sending prices for {$res['queued']} item(s) to " . implode(', ', $res['stores'])
            . ($res['skipped'] ? " — {$res['skipped']} skipped (not on Shopify)" : '')
            . '. Progress and any errors are in Sync History below.');
    }

    /** Save the sheet's prices on the sizes / products: selling price = after-discount, compare-at = before-discount (0 = no discount). */
    private function savePrices(array $items): int
    {
        $n = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($items, &$n) {
            $vars  = \App\Models\ProductVariation::whereIn('id', collect($items)->pluck('variation_id')->filter()->unique())->get()->keyBy('id');
            $prods = \App\Models\Product::whereIn('id', collect($items)->pluck('product_id')->unique())->get()->keyBy('id');

            foreach ($items as $i) {
                $selling = round((float) $i['new_price'], 2);
                $compare = isset($i['new_compare']) && is_numeric($i['new_compare']) && (float) $i['new_compare'] > 0 ? round((float) $i['new_compare'], 2) : 0;
                if ($selling <= 0) continue;
                if ($compare > 0 && $selling > $compare) {
                    throw new \RuntimeException(($vars[$i['variation_id'] ?? 0]->sku ?? ($prods[$i['product_id']]->sku ?? '?')) . ': the discount price is higher than the price before discount.');
                }
                $target = !empty($i['variation_id']) ? ($vars[$i['variation_id']] ?? null) : ($prods[$i['product_id']] ?? null);
                if (!$target) continue;
                $target->update(['selling_price' => $selling, 'compare_at_price' => $compare]);
                $n++;
            }
        });
        return $n;
    }

    /** @return array<int,array{code:string, price:?float, discount:?float}>  price/discount are null when the sheet has no such column */
    private function readSheet($file): array
    {
        $sheet = Excel::toArray(new class implements ToArray, WithCalculatedFormulas {
            public function array(array $array) {}
        }, $file)[0] ?? [];
        if (!$sheet) return [];

        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/\s+/', ' ', str_replace("\xEF\xBB\xBF", '', (string) $h)))), $sheet[0]);
        $find = function (array $names) use ($header) {
            foreach ($names as $name) { $i = array_search($name, $header, true); if ($i !== false) return $i; }
            return null;
        };
        $col      = $find(self::CODE_HEADERS);
        $priceCol = $find(['variant price', 'price', 'new price', 'actual price']);
        $discCol  = $find(['variant discount', 'discount', 'discount price', 'new discount price']);
        $num = function ($v) {
            $v = preg_replace('/[^0-9.\-]/', '', (string) $v);
            return $v === '' || !is_numeric($v) ? null : (float) $v;
        };

        $rows = $col === null ? $sheet : array_slice($sheet, 1);   // no recognised header → first column, every row
        $out  = [];
        foreach ($rows as $r) {
            $code = trim((string) ($r[$col ?? 0] ?? ''));
            if ($code === '') continue;
            $out[] = [
                'code'     => $code,
                'price'    => $col !== null && $priceCol !== null ? $num($r[$priceCol] ?? null) : null,
                'discount' => $col !== null && $discCol  !== null ? $num($r[$discCol]  ?? null) : null,
            ];
        }
        return $out;
    }
}
