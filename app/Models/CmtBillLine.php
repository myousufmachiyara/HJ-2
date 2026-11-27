<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CmtBillLine extends Model
{
    protected $fillable = ['cmt_bill_id', 'category_id', 'sku_count', 'qty', 'rate', 'amount',
        'fabric_id', 'fabric_variation_id', 'meters_per_pc', 'fabric_qty', 'fabric_rate', 'fabric_amount'];

    public function category() { return $this->belongsTo(ProductCategory::class, 'category_id'); }
    public function fabric()   { return $this->belongsTo(Product::class, 'fabric_id'); }
}
