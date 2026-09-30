<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockAdjustment extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'opening'    => 'Opening Stock',
        'count'      => 'Physical Count',
        'adjustment' => 'Manual Adjustment (+/−)',
    ];

    protected $fillable = ['adj_no', 'date', 'location_id', 'type', 'remarks', 'created_by'];

    public function items()    { return $this->hasMany(StockAdjustmentItem::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function creator()  { return $this->belongsTo(User::class, 'created_by'); }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public static function nextNo(): string
    {
        $max = static::withTrashed()->where('adj_no', 'like', 'ADJ-%')->lockForUpdate()->pluck('adj_no')
            ->map(fn ($n) => (int) substr($n, 4))->max() ?? 0;
        return 'ADJ-' . str_pad($max + 1, 5, '0', STR_PAD_LEFT);
    }
}
