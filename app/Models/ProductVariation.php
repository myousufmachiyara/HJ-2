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
        // Shopify sync — see 2026_09_17_000001 migration. Was previously
        // silently dropped on every import (not fillable, no column).
        'selling_price',
        'compare_at_price',   // per size; blank = product's default
    ];

    /** Selling price of this size: its own price, else the product's default. */
    public function salePrice(): float
    {
        if ($this->selling_price !== null) return (float) $this->selling_price;
        return (float) ($this->product?->selling_price ?? 0);
    }

    /** Compare-at (before discount) price of this size, else the product's; null when none. */
    public function comparePrice(): ?float
    {
        $v = $this->compare_at_price ?? $this->product?->compare_at_price;
        return $v !== null && (float) $v > 0 ? (float) $v : null;
    }

    // Auto barcode-generation removed — barcode is now entered manually
    // through the create/edit variation rows or via bulk import.

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