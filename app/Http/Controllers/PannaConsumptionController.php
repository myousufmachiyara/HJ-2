<?php

namespace App\Http\Controllers;

use App\Models\AttributeValue;
use App\Models\PannaConsumption;
use App\Services\PannaConsumption as Consumption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Products → PANNA Consumption: meters of fabric per piece for each product
 * category and PANNA, with optional size-wise exceptions.
 */
class PannaConsumptionController extends Controller
{
    public function index()
    {
        $pannaAttr  = Consumption::pannaAttributeId();
        $pannas     = $pannaAttr ? AttributeValue::where('attribute_id', $pannaAttr)->orderBy('id')->get() : collect();
        $categories = Consumption::articleCategories();
        $sizes      = AttributeValue::with('attribute:id,name')->when($pannaAttr, fn ($q) => $q->where('attribute_id', '!=', $pannaAttr))
                        ->orderBy('attribute_id')->orderBy('id')->get();

        $rules  = PannaConsumption::all();
        $matrix = $rules->whereNull('size_value_id')->mapWithKeys(fn ($r) => [$r->category_id . '-' . $r->panna_value_id => $r->consumption]);
        $bySize = $rules->whereNotNull('size_value_id')->values();

        return view('products.panna-consumption', compact('pannas', 'categories', 'sizes', 'matrix', 'bySize'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cons'                    => 'nullable|array',
            'cons.*.*'                => 'nullable|numeric|min:0',
            'sizes'                   => 'nullable|array',
            'sizes.*.category_id'     => 'nullable|integer|exists:product_categories,id',
            'sizes.*.panna_value_id'  => 'nullable|integer|exists:attribute_values,id',
            'sizes.*.size_value_id'   => 'nullable|integer|exists:attribute_values,id',
            'sizes.*.consumption'     => 'nullable|numeric|min:0',
        ]);

        $rows = [];
        foreach ($data['cons'] ?? [] as $catId => $byPanna) {
            foreach ((array) $byPanna as $pannaId => $val) {
                if ($val !== null && $val !== '' && (float) $val > 0) {
                    $rows["$catId-$pannaId-"] = ['category_id' => (int) $catId, 'panna_value_id' => (int) $pannaId, 'size_value_id' => null, 'consumption' => round((float) $val, 3)];
                }
            }
        }
        foreach (array_values($data['sizes'] ?? []) as $i => $s) {
            if (empty($s['category_id']) && empty($s['panna_value_id']) && empty($s['size_value_id'])) continue;   // empty line
            if (empty($s['category_id']) || empty($s['panna_value_id']) || empty($s['size_value_id']) || (float) ($s['consumption'] ?? 0) <= 0) {
                throw ValidationException::withMessages(["sizes.$i" => 'Size line ' . ($i + 1) . ': choose category, PANNA, size and enter the consumption.']);
            }
            $key = "{$s['category_id']}-{$s['panna_value_id']}-{$s['size_value_id']}";
            $rows[$key] = ['category_id' => (int) $s['category_id'], 'panna_value_id' => (int) $s['panna_value_id'],
                           'size_value_id' => (int) $s['size_value_id'], 'consumption' => round((float) $s['consumption'], 3)];
        }

        DB::transaction(function () use ($rows) {
            PannaConsumption::query()->delete();
            foreach ($rows as $r) PannaConsumption::create($r);
        });

        return redirect()->route('panna-consumption.index')->with('success', count($rows) . ' consumption rule(s) saved.');
    }
}
