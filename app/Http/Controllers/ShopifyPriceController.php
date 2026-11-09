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
        if ($request->hasFile('file')) $codes = array_merge($codes, $this->codesFromSheet($request->file('file')));

        if (!$codes) {
            return response()->json(['success' => false, 'message' => 'Paste at least one SKU / barcode, or upload a file.'], 422);
        }

        $resolved = $this->service->resolveCodes($codes);
        return response()->json([
            'success' => true,
            'rows'    => $this->service->describe($resolved['items']),
            'errors'  => $resolved['errors'],
        ]);
    }

    /** Step 2: queue the push for the rows the user ticked. */
    public function push(Request $request)
    {
        $refs = json_decode((string) $request->input('payload'), true);
        if (!is_array($refs) || !$refs) {
            return back()->with('error', 'Tick at least one item to send.');
        }
        $refs = array_values(array_map(fn ($r) => [
            'product_id'   => (int) ($r['product_id'] ?? 0),
            'variation_id' => !empty($r['variation_id']) ? (int) $r['variation_id'] : null,
        ], array_filter($refs, fn ($r) => !empty($r['product_id']))));

        $res = $this->service->dispatch($refs);

        if ($res['queued'] === 0) {
            return back()->with('error', 'Nothing was sent — none of the items is on a connected Shopify store.');
        }

        return redirect()->route('shopify.settings')->with('success',
            "Sending prices for {$res['queued']} item(s) to " . implode(', ', $res['stores'])
            . ($res['skipped'] ? " — {$res['skipped']} skipped (not on Shopify)" : '')
            . '. Progress and any errors are in Sync History below.');
    }

    private function codesFromSheet($file): array
    {
        $sheet = Excel::toArray(new class implements ToArray, WithCalculatedFormulas {
            public function array(array $array) {}
        }, $file)[0] ?? [];
        if (!$sheet) return [];

        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/\s+/', ' ', str_replace("\xEF\xBB\xBF", '', (string) $h)))), $sheet[0]);
        $col    = null;
        foreach (self::CODE_HEADERS as $name) {            // first matching header, in order of preference
            $i = array_search($name, $header, true);
            if ($i !== false) { $col = $i; break; }
        }

        $rows = $col === null ? $sheet : array_slice($sheet, 1);   // no recognised header → first column, every row
        return collect($rows)->map(fn ($r) => trim((string) ($r[$col ?? 0] ?? '')))->filter()->values()->all();
    }
}
