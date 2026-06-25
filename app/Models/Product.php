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
        'reorder_level',
        'max_stock_level',
        'minimum_order_qty',
        'measurement_unit',
        'item_type',
        'is_active',
    ];

    protected $casts = [
        'sku_opening_date' => 'date',
    ];

    // NOTE: the auto barcode-generation booted() hook has been removed on purpose.
    // Barcode is now a manually entered field (see create/edit forms + controller).

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
}