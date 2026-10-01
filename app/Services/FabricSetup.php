<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\FabricArticle;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Validation\ValidationException;

/**
 * Fabric (raw) setup shared by the product form and the fabric articles page:
 *   - one variation per PANNA (attribute "PANNA")
 *   - per PANNA: the articles made from it and consumption per piece
 */
class FabricSetup
{
    public static function pannaAttribute(): ?Attribute
    {
        return Attribute::with('values')->where('slug', 'panna')->first();
    }

    /** The fabric's variation for a PANNA value — created if it doesn't exist yet. */
    public static function pannaVariation(Product $fabric, int $pannaValueId): ProductVariation
    {
        $existing = $fabric->variations()
            ->whereHas('attributeValues', fn ($q) => $q->where('attribute_values.id', $pannaValueId))
            ->first();
        if ($existing) return $existing;

        $sku = Product::variationSku($fabric->sku, [$pannaValueId]);
        $base = $sku; $n = 2;
        while (ProductVariation::withTrashed()->where('sku', $sku)->exists()) {
            $sku = $base . '-' . $n++;
        }
        $variation = $fabric->variations()->create(['sku' => $sku, 'stock_quantity' => 0]);
        $variation->attributeValues()->sync([$pannaValueId]);

        return $variation;
    }

    /** Make sure the fabric has a variation for each selected PANNA value. */
    public static function syncPannas(Product $fabric, array $pannaValueIds): void
    {
        foreach (array_unique(array_filter(array_map('intval', $pannaValueIds))) as $vid) {
            self::pannaVariation($fabric, $vid);
        }
    }

    /**
     * Replace the fabric's article lines.
     * $rows: [['panna_value_id'|'fabric_variation_id', 'article_id', 'article_variation_id', 'consumption'], ...]
     */
    public static function saveArticles(Product $fabric, array $rows, string $field = 'fabric_articles'): int
    {
        $fabric->load('variations');
        $hasPannas = $fabric->variations->isNotEmpty() || collect($rows)->contains(fn ($r) => !empty($r['panna_value_id']));
        $clean = [];

        foreach (array_values($rows) as $i => $r) {
            // a line without an article is ignored (e.g. the empty starter line, or a fabric
            // created before its articles exist — articles can be added later by editing the fabric)
            if (empty($r['article_id'])) continue;
            $line = $i + 1;

            $article = Product::find($r['article_id'] ?? null);
            if (!$article || $article->item_type !== 'fg') {
                throw ValidationException::withMessages(["$field.$i.article_id" => "Article line $line: select a finished-good article."]);
            }
            $cons = (float) ($r['consumption'] ?? 0);
            if ($cons <= 0) {
                throw ValidationException::withMessages(["$field.$i.consumption" => "Article line $line: consumption must be more than 0."]);
            }

            $fv = null;
            if (!empty($r['fabric_variation_id'])) {
                $fv = (int) $r['fabric_variation_id'];
                if (!$fabric->variations->contains('id', $fv)) {
                    throw ValidationException::withMessages(["$field.$i.fabric_variation_id" => "Article line $line: invalid PANNA."]);
                }
            } elseif (!empty($r['panna_value_id'])) {
                $fv = self::pannaVariation($fabric, (int) $r['panna_value_id'])->id;
            } elseif ($hasPannas) {
                throw ValidationException::withMessages(["$field.$i.panna_value_id" => "Article line $line: select the PANNA."]);
            }

            $av = !empty($r['article_variation_id']) ? (int) $r['article_variation_id'] : null;
            if ($av && !ProductVariation::where('id', $av)->where('product_id', $article->id)->exists()) {
                throw ValidationException::withMessages(["$field.$i.article_variation_id" => "Article line $line: size does not belong to {$article->name}."]);
            }

            $clean[$fv . '-' . $article->id . '-' . $av] = [
                'fabric_id'            => $fabric->id,
                'fabric_variation_id'  => $fv,
                'article_id'           => $article->id,
                'article_variation_id' => $av,
                'consumption'          => round($cons, 3),
            ];
        }

        FabricArticle::where('fabric_id', $fabric->id)->delete();
        foreach ($clean as $row) {
            FabricArticle::create($row);
        }

        return count($clean);
    }

    /** PANNA value id of a fabric variation (for pre-filling the product form). */
    public static function pannaValueOf(?ProductVariation $variation): ?int
    {
        if (!$variation) return null;
        return $variation->attributeValues()
            ->whereHas('attribute', fn ($q) => $q->where('slug', 'panna'))
            ->value('attribute_values.id');
    }
}
