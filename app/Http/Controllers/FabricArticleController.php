<?php

namespace App\Http\Controllers;

use App\Imports\RawRowsImport;
use App\Models\FabricArticle;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Fabric (raw) → articles setup:
 * "From fabric ABC in panna 44" we make article XYZ, size M uses 2.75 m per piece."
 * Used by FG receiving to consume fabric at the CMT and by the CMT fabric report.
 */
class FabricArticleController extends Controller
{
    public function index($productId)
    {
        $fabric   = Product::with(['variations.attributeValues', 'measurementUnit'])->findOrFail($productId);
        $rows     = FabricArticle::with(['fabricVariation:id,sku', 'article:id,name,sku', 'articleVariation:id,sku'])
            ->where('fabric_id', $fabric->id)->get()
            ->sortBy(fn ($r) => ($r->fabricVariation->sku ?? '') . '|' . ($r->article->name ?? '') . '|' . ($r->articleVariation->sku ?? ''))
            ->values();
        $articles = Product::with('variations:id,product_id,sku')->where('item_type', 'fg')->orderBy('name')->get(['id', 'name', 'sku']);

        return view('products.fabric-articles', compact('fabric', 'rows', 'articles'));
    }

    public function store(Request $request, $productId)
    {
        $fabric = Product::with('variations')->findOrFail($productId);
        $data = $request->validate([
            'rows'                        => 'nullable|array',
            'rows.*.fabric_variation_id'  => 'nullable|integer',
            'rows.*.article_id'           => 'required|exists:products,id',
            'rows.*.article_variation_id' => 'nullable|integer',
            'rows.*.consumption'          => 'required|numeric|gt:0',
        ], [
            'rows.*.consumption.gt' => 'Consumption must be more than 0.',
        ]);

        $rows = $this->normalise($fabric, $data['rows'] ?? []);

        DB::transaction(function () use ($fabric, $rows) {
            FabricArticle::where('fabric_id', $fabric->id)->delete();
            foreach ($rows as $r) {
                FabricArticle::create($r + ['fabric_id' => $fabric->id]);
            }
        });

        return redirect()->route('products.fabric-articles', $fabric->id)
            ->with('success', count($rows) . ' article consumption line(s) saved for ' . $fabric->name . '.');
    }

    /**
     * Excel import — columns: Panna | Article Code (product or variation SKU / barcode) | Consumption
     * Adds / updates lines; existing lines not in the file are kept.
     */
    public function import(Request $request, $productId)
    {
        $fabric = Product::with('variations.attributeValues')->findOrFail($productId);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt']);

        $import = new RawRowsImport();
        Excel::import($import, $request->file('file'));

        $saved = 0;
        $errors = [];
        foreach ($import->getRows() as $i => $row) {
            $row  = array_values($row->toArray());
            $line = $i + 2;
            [$panna, $code, $cons] = array_pad(array_map(fn ($v) => trim((string) $v), $row), 3, '');
            if ($code === '') continue;

            $fv = null;
            if ($fabric->variations->isNotEmpty()) {
                $fv = $this->findPanna($fabric, $panna);
                if (!$fv) { $errors[] = "Row {$line}: panna '{$panna}' is not a variation of {$fabric->name}"; continue; }
            }

            $var = ProductVariation::where('sku', $code)->orWhere('barcode', $code)->first();
            $art = $var ? $var->product : Product::where('sku', $code)->orWhere('barcode', $code)->first();
            if (!$art || $art->item_type !== 'fg') { $errors[] = "Row {$line}: article '{$code}' not found (or not a finished good)"; continue; }
            if (!is_numeric($cons) || (float) $cons <= 0) { $errors[] = "Row {$line}: consumption missing for '{$code}'"; continue; }

            FabricArticle::updateOrCreate(
                ['fabric_id' => $fabric->id, 'fabric_variation_id' => $fv?->id, 'article_id' => $art->id, 'article_variation_id' => $var?->id],
                ['consumption' => (float) $cons]
            );
            $saved++;
        }

        $back = redirect()->route('products.fabric-articles', $fabric->id)->with('success', "{$saved} line(s) imported.");
        return $errors ? $back->with('error', implode("\n", array_slice($errors, 0, 30))) : $back;
    }

    /** AJAX for FG receiving: fabric / panna options for an article, with what the vendor still holds. */
    public function optionsForArticle(Request $request)
    {
        $request->validate(['product_id' => 'required|integer', 'vendor_id' => 'nullable|integer']);
        $vendorLoc = $request->vendor_id ? \App\Models\Location::forAccount((int) $request->vendor_id) : null;

        $opts = FabricArticle::optionsFor((int) $request->product_id, $request->variation_id ? (int) $request->variation_id : null)
            ->map(fn ($o) => [
                'value'       => $o->fabric_id . ':' . ($o->fabric_variation_id ?? ''),
                'label'       => $o->fabricLabel(),
                'consumption' => $o->consumption,
                'at_vendor'   => $vendorLoc ? \App\Services\Inventory::balance($vendorLoc->id, $o->fabric_id, $o->fabric_variation_id) : null,
            ])->values();

        return response()->json($opts);
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function normalise(Product $fabric, array $rows): array
    {
        $pannaIds = $fabric->variations->pluck('id')->all();
        $out = [];
        foreach ($rows as $i => $r) {
            $fv = !empty($r['fabric_variation_id']) ? (int) $r['fabric_variation_id'] : null;
            if ($pannaIds && !$fv) {
                throw ValidationException::withMessages(["rows.$i.fabric_variation_id" => 'Line ' . ($i + 1) . ': select the panna.']);
            }
            if ($fv && !in_array($fv, $pannaIds, true)) {
                throw ValidationException::withMessages(["rows.$i.fabric_variation_id" => 'Line ' . ($i + 1) . ': invalid panna.']);
            }
            $av = !empty($r['article_variation_id']) ? (int) $r['article_variation_id'] : null;
            if ($av && !ProductVariation::where('id', $av)->where('product_id', $r['article_id'])->exists()) {
                throw ValidationException::withMessages(["rows.$i.article_variation_id" => 'Line ' . ($i + 1) . ': size does not belong to the article.']);
            }
            $key = $fv . '-' . $r['article_id'] . '-' . $av;
            $out[$key] = [
                'fabric_variation_id'  => $fv,
                'article_id'           => (int) $r['article_id'],
                'article_variation_id' => $av,
                'consumption'          => round((float) $r['consumption'], 3),
            ];
        }
        return array_values($out);
    }

    private function findPanna(Product $fabric, string $panna): ?ProductVariation
    {
        $norm = fn ($v) => strtoupper(preg_replace('/[^A-Za-z0-9.]/', '', (string) $v));
        return $fabric->variations->first(fn ($v) => $norm($v->sku) === $norm($panna)
            || $v->attributeValues->contains(fn ($av) => $norm($av->value) === $norm($panna)));
    }
}
