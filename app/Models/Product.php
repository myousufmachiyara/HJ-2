<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'subcategory_id',
        'vendor_id',
        'brand',
        'name',
        'sku',
        'barcode',
        'sku_opening_date',
        'description',
        'weight',
        'cmt_cost',
        'cost_price',
        'opening_stock',
        'selling_price',
        'compare_at_price',
        'consumption',
        'fabric_id',
        'reorder_level',
        'max_stock_level',
        'minimum_order_qty',
        'measurement_unit',
        'item_type',
        'is_active',
        // Shopify sync identity — see 2026_09_17_000001 migration.
        'shopify_store_id',
        'shopify_product_id',
    ];

    protected $casts = [
        'sku_opening_date' => 'date',
    ];

    // NOTE: the auto barcode-generation booted() hook has been removed on purpose.
    // Barcode is now a manually entered field (see create/edit forms + controller).

    /*
    |--------------------------------------------------------------------------
    | SKU format:  {CATEGORY CODE}-{5-digit running number per category}
    |              e.g. KRT-00001 ; variation = KRT-00001-M (attribute values)
    | Change SKU_DIGITS / skuPrefix() here if the format ever changes.
    |--------------------------------------------------------------------------
    */
    public const SKU_DIGITS = 5;

    public static function skuPrefix(int $categoryId): string
    {
        $code = ProductCategory::whereKey($categoryId)->value('code') ?: 'ITEM';
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)) ?: 'ITEM';
        return $code . '-';
    }

    /** Highest number already used for this prefix (so manual / imported SKUs are respected). */
    protected static function maxSkuNumber(string $prefix): int
    {
        return (int) static::withTrashed()
            ->where('sku', 'like', $prefix . '%')
            ->pluck('sku')
            ->map(function ($sku) use ($prefix) {
                $rest = substr($sku, strlen($prefix));
                return preg_match('/^(\d+)$/', $rest, $m) ? (int) $m[1] : 0;
            })
            ->max();
    }

    /** Preview only — does not reserve the number. */
    public static function previewSku(int $categoryId): string
    {
        $prefix = static::skuPrefix($categoryId);
        $seq    = \Illuminate\Support\Facades\DB::table('barcode_sequences')->where('prefix', 'SKU:' . $prefix)->value('next_number') ?? 1;
        $next   = max((int) $seq, static::maxSkuNumber($prefix) + 1);
        return $prefix . str_pad($next, self::SKU_DIGITS, '0', STR_PAD_LEFT);
    }

    /** Reserve and return the next SKU for a category (call inside a DB transaction). */
    public static function generateSku(int $categoryId): string
    {
        $prefix = static::skuPrefix($categoryId);
        $key    = 'SKU:' . $prefix;
        $db     = \Illuminate\Support\Facades\DB::table('barcode_sequences');

        $row = (clone $db)->where('prefix', $key)->lockForUpdate()->first();
        if (!$row) {
            (clone $db)->insert(['prefix' => $key, 'next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $row = (clone $db)->where('prefix', $key)->lockForUpdate()->first();
        }

        $next = max((int) $row->next_number, static::maxSkuNumber($prefix) + 1);
        while (static::withTrashed()->where('sku', $prefix . str_pad($next, self::SKU_DIGITS, '0', STR_PAD_LEFT))->exists()) {
            $next++;
        }
        (clone $db)->where('prefix', $key)->update(['next_number' => $next + 1, 'updated_at' => now()]);

        return $prefix . str_pad($next, self::SKU_DIGITS, '0', STR_PAD_LEFT);
    }

    /** Variation SKU = product SKU + attribute values, e.g. KRT-00001-M or KRT-00001-RED-M */
    public static function variationSku(string $productSku, array $attributeValueIds): string
    {
        $values = AttributeValue::whereIn('id', $attributeValueIds)
            ->orderBy('attribute_id')->pluck('value')
            ->map(fn ($v) => strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $v)))
            ->filter()->implode('-');

        return $values !== '' ? $productSku . '-' . $values : $productSku;
    }

    /** For a fabric: the articles made from it (per panna) and consumption. */
    public function fabricArticles()
    {
        return $this->hasMany(FabricArticle::class, 'fabric_id');
    }

    /** For an article: the fabrics it can be made from. */
    public function articleFabrics()
    {
        return $this->hasMany(FabricArticle::class, 'article_id');
    }

    public function fabric()
    {
        return $this->belongsTo(Product::class, 'fabric_id');
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id');
    }

    public function variations()
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function measurementUnit()
    {
        return $this->belongsTo(MeasurementUnit::class, 'measurement_unit');
    }

    public function purchaseInvoices()
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'item_id');
    }

    public function shopifyStore()
    {
        return $this->belongsTo(ShopifyStore::class, 'shopify_store_id');
    }
}