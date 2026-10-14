<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\Location;
use App\Models\PannaConsumption as Rule;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fabric consumption by PRODUCT CATEGORY and PANNA.
 *
 *   rule:   category + PANNA (+ optional size)  →  meters per piece
 *   lookup: the received article's category, the PANNA the CMT used, the article's
 *           size — a size rule wins over the category's "all sizes" rule.
 *
 * Any fabric that comes in that PANNA can be chosen at FG receiving; the ones the
 * CMT actually holds are listed first.
 */
class PannaConsumption
{
    private static ?int $pannaAttrId = null;

    public static function pannaAttributeId(): ?int
    {
        return self::$pannaAttrId ??= Attribute::where('slug', 'panna')->value('id');
    }

    /** [panna_value_id => ['all' => ?float, 'sizes' => [size_value_id => float]]] */
    public static function rulesFor(?int $categoryId): array
    {
        if (!$categoryId) return [];
        $out = [];
        foreach (Rule::where('category_id', $categoryId)->get() as $r) {
            if ($r->size_value_id) $out[$r->panna_value_id]['sizes'][$r->size_value_id] = (float) $r->consumption;
            else                   $out[$r->panna_value_id]['all'] = (float) $r->consumption;
        }
        return $out;
    }

    /** Meters per piece for a category + PANNA + the article's size values (null = no rule). */
    public static function consumptionFor(?int $categoryId, int $pannaValueId, array $sizeValueIds = []): ?float
    {
        $rule = self::rulesFor($categoryId)[$pannaValueId] ?? null;
        if (!$rule) return null;
        foreach ($sizeValueIds as $sid) {
            if (isset($rule['sizes'][$sid])) return $rule['sizes'][$sid];
        }
        return $rule['all'] ?? null;
    }

    /** Size / age values of an article variation (everything except PANNA). */
    public static function sizeValueIds(?int $variationId): array
    {
        if (!$variationId) return [];
        return DB::table('product_variation_attribute_values as x')
            ->join('attribute_values as v', 'v.id', '=', 'x.attribute_value_id')
            ->where('x.product_variation_id', $variationId)
            ->when(self::pannaAttributeId(), fn ($q, $p) => $q->where('v.attribute_id', '!=', $p))
            ->pluck('v.id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * Fabric + PANNA options for one received article line.
     * Each: value "fabricId:variationId", label, consumption, at_vendor, fabric_id, fabric_variation_id.
     */
    public static function optionsFor(int $productId, ?int $variationId, ?int $vendorAccountId = null): Collection
    {
        $product = Product::find($productId);
        if (!$product || $product->item_type === 'raw') return collect();

        $rules = self::rulesFor($product->category_id);
        if (!$rules || !self::pannaAttributeId()) return collect();
        $sizes = self::sizeValueIds($variationId);

        $fabrics = DB::table('product_variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('product_variation_attribute_values as x', 'x.product_variation_id', '=', 'v.id')
            ->join('attribute_values as av', 'av.id', '=', 'x.attribute_value_id')
            ->where('p.item_type', 'raw')->whereNull('p.deleted_at')->whereNull('v.deleted_at')
            ->where('av.attribute_id', self::pannaAttributeId())
            ->whereIn('av.id', array_keys($rules))
            ->select('p.id as fabric_id', 'p.name', 'p.sku as fabric_sku', 'v.id as variation_id', 'av.id as panna_id', 'av.value as panna')
            ->get();

        $atVendor = collect();
        $loc = $vendorAccountId ? Location::forAccount($vendorAccountId) : null;
        if ($loc && $fabrics->isNotEmpty()) {
            $atVendor = StockLedger::where('location_id', $loc->id)
                ->whereIn('variation_id', $fabrics->pluck('variation_id'))
                ->groupBy('variation_id')->selectRaw('variation_id, SUM(qty) AS qty')->pluck('qty', 'variation_id');
        }

        return $fabrics->map(function ($f) use ($rules, $sizes, $loc, $atVendor) {
            $rule = $rules[$f->panna_id];
            $cons = null;
            foreach ($sizes as $sid) { if (isset($rule['sizes'][$sid])) { $cons = $rule['sizes'][$sid]; break; } }
            $cons ??= $rule['all'] ?? null;
            if ($cons === null) return null;
            return (object) [
                'value'               => $f->fabric_id . ':' . $f->variation_id,
                'label'               => $f->name . ' — ' . $f->panna,
                'consumption'         => $cons,
                'at_vendor'           => $loc ? round((float) ($atVendor[$f->variation_id] ?? 0), 3) : null,
                'fabric_id'           => (int) $f->fabric_id,
                'fabric_variation_id' => (int) $f->variation_id,
            ];
        })->filter()
          ->sortBy(fn ($o) => [($o->at_vendor ?? 0) > 0 ? 0 : 1, $o->label])
          ->values();
    }

    /** What could still be cut from $remaining meters of one PANNA (all-sizes rules). */
    public static function canMake(?int $pannaValueId, float $remaining): Collection
    {
        if (!$pannaValueId) return collect();
        return Rule::with('category:id,name')->where('panna_value_id', $pannaValueId)->whereNull('size_value_id')->get()
            ->map(fn ($r) => (object) [
                'article'     => $r->category->name ?? '-',
                'consumption' => (float) $r->consumption,
                'pcs'         => $remaining > 0 && $r->consumption > 0 ? floor($remaining / $r->consumption) : 0,
            ])->sortBy('article')->values();
    }

    /** Categories that are finished goods (everything except the fabric category). */
    public static function articleCategories(): Collection
    {
        return ProductCategory::orderBy('name')->get()
            ->reject(fn ($c) => strtoupper((string) $c->code) === 'FAB')->values();
    }
}
