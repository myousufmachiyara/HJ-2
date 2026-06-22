<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'stock_quantity',
    ];

    protected static function booted()
    {
        static::creating(function ($variation) {
            if (empty($variation->barcode)) {
                $product = $variation->product;
                $prefix  = strtoupper($product->item_type ?? 'PRD') . '-VAR-';
                $variation->barcode = generateGlobalBarcode($prefix);
            }
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues()
    {
        return $this->belongsToMany(AttributeValue::class, 'product_variation_attribute_values')
                    ->withTimestamps();
    }

    public function values()
    {
        return $this->hasMany(ProductVariationAttributeValue::class);
    }

    public function receivings()
    {
        return $this->hasMany(ProductionReceivingDetail::class, 'variation_id');
    }
}