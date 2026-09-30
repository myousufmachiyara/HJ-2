<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentItem extends Model
{
    protected $fillable = [
        'stock_adjustment_id', 'product_id', 'variation_id',
        'system_qty', 'counted_qty', 'quantity', 'unit_cost', 'remarks',
    ];

    public function adjustment() { return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id'); }
    public function product()    { return $this->belongsTo(Product::class); }
    public function variation()  { return $this->belongsTo(ProductVariation::class, 'variation_id'); }
}
