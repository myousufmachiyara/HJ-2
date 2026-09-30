<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One signed stock movement at one location (+ in, − out).
 * Written only through App\Services\Inventory::sync().
 */
class StockLedger extends Model
{
    protected $table = 'stock_ledger';

    protected $fillable = [
        'date', 'product_id', 'variation_id', 'location_id', 'qty', 'unit_cost',
        'source_type', 'source_id', 'remarks',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function product()   { return $this->belongsTo(Product::class); }
    public function variation() { return $this->belongsTo(ProductVariation::class, 'variation_id'); }
    public function location()  { return $this->belongsTo(Location::class); }
    public function source()    { return $this->morphTo(); }
}
