<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Meters of fabric per piece for a product category cut from one PANNA (optionally per size). */
class PannaConsumption extends Model
{
    protected $fillable = ['category_id', 'panna_value_id', 'size_value_id', 'consumption'];

    protected $casts = ['consumption' => 'float'];

    public function category()  { return $this->belongsTo(ProductCategory::class, 'category_id'); }
    public function panna()     { return $this->belongsTo(AttributeValue::class, 'panna_value_id'); }
    public function size()      { return $this->belongsTo(AttributeValue::class, 'size_value_id'); }
}
