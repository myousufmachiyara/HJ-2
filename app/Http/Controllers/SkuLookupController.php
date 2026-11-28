<?php

namespace App\Http\Controllers;

use App\Exports\SkuLookupExport;
use App\Models\Product;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Products list → "Get SKUs": paste product names (or upload a sheet whose first column holds them)
 * and download every variation's SKU with its price before and after discount, in the order given.
 *
 * A name is matched exactly first (ignoring case and extra spaces); when nothing matches exactly,
 * products whose name CONTAINS the text are listed and marked "Partial". Unknown names get a
 * "Not found" row so the list numbers still line up with your list.
 */
class SkuLookupController extends Controller
{
    private const MAX_NAMES = 5000;

    public function download(Request $request)
    {
        $request->validate([
            'names' => 'nullable|string',
            'file'  => 'nullable|file|mimes:xlsx,xls,csv,txt',
        ]);

        $names = $this->readNames($request);
        if (!$names) return back()->with('error', 'Enter at least one product name (one per line) or upload a sheet.');
        if (count($names) > self::MAX_NAMES) return back()->with('error', 'Too many names — maximum ' . self::MAX_NAMES . ' at a time.');

        $rows = [];
        $notFound = 0;
        foreach ($names as $i => $name) {
            $no = $i + 1;
            [$products, $how] = $this->find($name);

            if ($products->isEmpty()) {
                $notFound++;
                $rows[] = [$no, $name, 'Not found', '', '', '', '', '', '', '', '', ''];
                continue;
            }

            foreach ($products as $p) {
                $variations = $p->variations->sortBy('id');
                if ($variations->isEmpty()) {
                    [$before, $after] = $this->prices($p, null);
                    $rows[] = [$no, $name, $how, $p->name, $p->brand, $p->category->name ?? '', $p->sku, $p->sku, '', $p->barcode, $before, $after];
                    continue;
                }
                foreach ($variations as $v) {
                    [$before, $after] = $this->prices($p, $v);
                    $rows[] = [
                        $no, $name, $how, $p->name, $p->brand, $p->category->name ?? '', $p->sku,
                        $v->sku, $v->attributeValues->pluck('value')->implode(' / '), $v->barcode, $before, $after,
                    ];
                }
            }
        }

        return Excel::download(new SkuLookupExport($rows), 'product-skus-' . now()->format('Ymd-His') . '.xlsx');
    }

    /** @return string[] names in the order given (blank lines dropped, duplicates kept so numbers match) */
    private function readNames(Request $request): array
    {
        $raw = [];
        if ($request->hasFile('file')) {
            $sheet = Excel::toArray(new class implements ToArray { public function array(array $a) {} }, $request->file('file'))[0] ?? [];
            foreach ($sheet as $row) $raw[] = (string) ($row[0] ?? '');
        }
        if (filled($request->names)) {
            $raw = array_merge($raw, preg_split('/\r\n|\r|\n/', (string) $request->names));
        }

        $names = [];
        foreach ($raw as $n) {
            $n = trim(preg_replace('/\s+/u', ' ', $n));
            if ($n !== '') $names[] = $n;
        }
        // drop a header row copied with the list
        if ($names && in_array(mb_strtolower($names[0]), ['product name', 'product names', 'name', 'item name', 'product'], true)) array_shift($names);
        return $names;
    }

    /** @return array{0:\Illuminate\Support\Collection,1:string} */
    private function find(string $name): array
    {
        $with = ['variations.attributeValues', 'category:id,name'];
        $exact = Product::with($with)->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->orderBy('id')->get();
        if ($exact->isNotEmpty()) return [$exact, 'Exact'];

        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name);
        $part = Product::with($with)->where('name', 'like', "%{$like}%")->orderBy('name')->limit(25)->get();
        return [$part, 'Partial'];
    }

    /** [price before discount, price after discount]; "after" is blank when there is no discount. */
    private function prices(Product $p, $v): array
    {
        $selling = $v?->selling_price ?? $p->selling_price;
        $compare = $v?->compare_at_price ?? $p->compare_at_price;
        $selling = $selling !== null ? (float) $selling : null;
        $compare = ($compare !== null && (float) $compare > 0) ? (float) $compare : null;

        return [$compare ?? $selling, $compare !== null ? $selling : null];
    }
}
