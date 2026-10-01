<?php
// app/Models/SaleInvoiceItem.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleInvoiceItem extends Model
{
    protected $fillable = [
        'sale_invoice_id', 'product_id', 'variation_id', 'item_name',
        'sale_price', 'discount', 'discount_amount', 'quantity', 'unit_cost', 'unit', 'remarks',
    ];

    public function invoice()
    {
        return $this->belongsTo(SaleInvoice::class, 'sale_invoice_id'); // ← add FK
    }
    public function product()    { return $this->belongsTo(Product::class); }
    public function variation()  { return $this->belongsTo(ProductVariation::class, 'variation_id'); }
    public function measurementUnit() { return $this->belongsTo(MeasurementUnit::class, 'unit'); }

    public function getLineTotal(): float
    {
        return round(self::netUnitPrice((float) $this->sale_price, (float) ($this->discount ?? 0), (float) ($this->discount_amount ?? 0)) * (float) $this->quantity, 2);
    }

    /** Price after line discount: % first, then Rs per piece; never below 0. */
    public static function netUnitPrice(float $price, float $discountPct, float $discountAmount): float
    {
        return max(0, $price - ($price * $discountPct / 100) - $discountAmount);
    }
}