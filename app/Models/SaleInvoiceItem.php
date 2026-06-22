<?php
// app/Models/SaleInvoiceItem.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleInvoiceItem extends Model
{
    protected $fillable = [
        'sale_invoice_id', 'product_id', 'variation_id', 'item_name',
        'sale_price', 'discount', 'quantity', 'unit', 'remarks',
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
        $discountedPrice = $this->sale_price - ($this->sale_price * ($this->discount ?? 0) / 100);
        return round($discountedPrice * $this->quantity, 2);
    }
}