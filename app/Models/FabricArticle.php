<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "With fabric X in panna Y we make article Z (size S) using N meters per piece."
 * fabric_variation_id = panna (null when the fabric has no panna variations)
 * article_variation_id = size (null = same consumption for all sizes)
 */
class FabricArticle extends Model
{
    protected $fillable = ['fabric_id', 'fabric_variation_id', 'article_id', 'article_variation_id', 'consumption'];

    protected $casts = ['consumption' => 'float'];

    public function fabric()           { return $this->belongsTo(Product::class, 'fabric_id'); }
    public function fabricVariation()  { return $this->belongsTo(ProductVariation::class, 'fabric_variation_id'); }
    public function article()          { return $this->belongsTo(Product::class, 'article_id'); }
    public function articleVariation() { return $this->belongsTo(ProductVariation::class, 'article_variation_id'); }

    public function fabricLabel(): string
    {
        return ($this->fabric->name ?? '#' . $this->fabric_id) . ($this->fabricVariation ? ' — ' . $this->fabricVariation->sku : '');
    }

    public function articleLabel(): string
    {
        return ($this->article->name ?? '#' . $this->article_id) . ' — ' . ($this->articleVariation->sku ?? 'all sizes');
    }

    /**
     * Fabric options for one received article line, most specific first:
     * exact size match, then "all sizes". Returns a collection of FabricArticle.
     */
    public static function optionsFor(int $articleId, ?int $articleVariationId)
    {
        $rows = static::with(['fabric:id,name,sku', 'fabricVariation:id,sku'])
            ->where('article_id', $articleId)
            ->where(fn ($q) => $q->whereNull('article_variation_id')
                ->when($articleVariationId, fn ($w) => $w->orWhere('article_variation_id', $articleVariationId)))
            ->get();

        // a size-specific row overrides the "all sizes" row for the same fabric+panna
        return $rows->sortByDesc(fn ($r) => $r->article_variation_id ? 1 : 0)
            ->unique(fn ($r) => $r->fabric_id . '-' . ($r->fabric_variation_id ?? 0))
            ->values();
    }
}
