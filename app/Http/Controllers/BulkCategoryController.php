<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bulk fix of product categories.
 *
 *   1. (optional) download every product with its current category as a CSV
 *   2. fill the "New Category Code" column (or edit "Category Code") and upload it
 *   3. review what would change, then apply
 *
 * Only the category changes. The product SKU, barcode and Shopify / LAAM links are never touched.
 */
class BulkCategoryController extends Controller
{
    private const SKU_HEADERS  = ['product sku', 'sku'];
    private const NEW_HEADERS  = ['new category code', 'new category', 'category code', 'category'];

    public function index()
    {
        return view('products.bulk-category', [
            'categories' => ProductCategory::orderBy('code')->get(['id', 'name', 'code']),
            'counts'     => Product::selectRaw('category_id, COUNT(*) AS n')->groupBy('category_id')->pluck('n', 'category_id'),
        ]);
    }

    /** Every product: SKU, name, current category (+ an empty "New Category Code" column to fill). */
    public function export()
    {
        $cats = ProductCategory::pluck('code', 'id');
        $name = 'products-categories-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($cats) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");                                   // so Excel reads it as UTF-8
            fputcsv($out, ['Product SKU', 'Product Name', 'Current Category Code', 'New Category Code']);
            Product::orderBy('id')->select(['id', 'sku', 'name', 'category_id'])->chunk(2000, function ($chunk) use ($out, $cats) {
                foreach ($chunk as $p) fputcsv($out, [$p->sku, $p->name, $cats[$p->category_id] ?? '', '']);
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Step 1 (AJAX): read the sheet and say what would change. Nothing is saved. */
    public function preview(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt']);

        $sheet = Excel::toArray(new class implements ToArray, WithCalculatedFormulas {
            public function array(array $array) {}
        }, $request->file('file'))[0] ?? [];
        if (count($sheet) < 2) return response()->json(['success' => false, 'message' => 'The file is empty.'], 422);

        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/\s+/', ' ', str_replace("\xEF\xBB\xBF", '', (string) $h)))), $sheet[0]);
        $skuCol = $this->col($header, self::SKU_HEADERS);
        $catCol = $this->col($header, self::NEW_HEADERS);
        if ($skuCol === null || $catCol === null) {
            return response()->json(['success' => false, 'message' => 'The file needs a "Product SKU" column and a "New Category Code" (or "Category Code") column.'], 422);
        }

        // product SKU → wanted category code (the same product may appear on many rows, e.g. one per size)
        $wanted = [];
        $conflicts = [];
        foreach (array_slice($sheet, 1) as $r) {
            $sku  = trim((string) ($r[$skuCol] ?? ''));
            $code = strtoupper(trim((string) ($r[$catCol] ?? '')));
            if ($sku === '' || $code === '') continue;                      // blank new code = leave this product as it is
            $key = mb_strtolower($sku);
            if (isset($wanted[$key]) && $wanted[$key]['code'] !== $code) $conflicts[$sku] = true;
            $wanted[$key] = ['sku' => $sku, 'code' => $code];
        }
        if (!$wanted) return response()->json(['success' => false, 'message' => 'No row has a category code to apply.'], 422);

        $cats = ProductCategory::get(['id', 'name', 'code'])->keyBy(fn ($c) => strtoupper($c->code));
        $byId = $cats->keyBy('id');

        $products = Product::whereIn(DB::raw('LOWER(sku)'), array_keys($wanted))->get(['id', 'sku', 'name', 'category_id'])
            ->keyBy(fn ($p) => mb_strtolower($p->sku));

        $changes = $unknownSku = $unknownCode = [];
        $same = 0;
        foreach ($wanted as $key => $w) {
            $p = $products[$key] ?? null;
            if (!$p)                       { $unknownSku[] = $w['sku']; continue; }
            $c = $cats[$w['code']] ?? null;
            if (!$c)                       { $unknownCode[$w['code']] = ($unknownCode[$w['code']] ?? 0) + 1; continue; }
            if ((int) $p->category_id === (int) $c->id) { $same++; continue; }
            $changes[] = [
                'product_id'  => $p->id, 'category_id' => $c->id,
                'sku'         => $p->sku, 'name' => $p->name,
                'from'        => $byId[$p->category_id]->code ?? '—', 'to' => $c->code,
            ];
        }

        return response()->json([
            'success'      => true,
            'changes'      => $changes,
            'same'         => $same,
            'unknown_sku'  => array_slice($unknownSku, 0, 50),
            'unknown_sku_n'=> count($unknownSku),
            'unknown_code' => $unknownCode,
            'conflicts'    => array_slice(array_keys($conflicts), 0, 50),
        ]);
    }

    /** Step 2: apply the reviewed changes. */
    public function apply(Request $request)
    {
        $changes = json_decode((string) $request->input('payload'), true);
        if (!is_array($changes) || !$changes) return back()->with('error', 'Nothing to apply.');

        $byCat = [];
        foreach ($changes as $c) {
            if (!empty($c['product_id']) && !empty($c['category_id'])) $byCat[(int) $c['category_id']][] = (int) $c['product_id'];
        }
        $valid = ProductCategory::whereIn('id', array_keys($byCat))->pluck('id')->all();

        $n = 0;
        DB::transaction(function () use ($byCat, $valid, &$n) {
            foreach ($byCat as $catId => $ids) {
                if (!in_array($catId, $valid, true)) continue;
                $subIds = ProductSubcategory::where('category_id', $catId)->pluck('id');
                foreach (array_chunk($ids, 1000) as $chunk) {
                    // a sub-category that belongs to the old category no longer fits
                    Product::whereIn('id', $chunk)->whereNotNull('subcategory_id')->whereNotIn('subcategory_id', $subIds)->update(['subcategory_id' => null]);
                    $n += Product::whereIn('id', $chunk)->update(['category_id' => $catId]);
                }
            }
        });

        return redirect()->route('bulk-category.index')->with('success', "Category changed for {$n} product(s).");
    }

    private function col(array $header, array $names): ?int
    {
        foreach ($names as $name) {                      // first matching header, in order of preference
            $i = array_search($name, $header, true);
            if ($i !== false) return $i;
        }
        return null;
    }
}
