<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaamInvoiceItem extends Model
{
    protected $guarded = [];

    protected $casts = ['order_date' => 'date', 'realization_date' => 'date'];

    public function invoice()   { return $this->belongsTo(LaamInvoice::class, 'laam_invoice_id'); }
    public function product()   { return $this->belongsTo(Product::class); }
    public function variation() { return $this->belongsTo(ProductVariation::class, 'variation_id'); }
}
