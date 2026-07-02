<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // soft delete support

class StockTransfer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',          // ← ADD
        'date',
        'from_location_id',
        'to_location_id',
        'remarks',       // added remarks to match table
        'created_by'
    ];

    // Relationships
    public function details()
    {
        return $this->hasMany(StockTransferDetail::class, 'transfer_id');
    }

    public function fromLocation()
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation()
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Convenience: is this a customer-facing document (DC or return DC)?
    public function isDeliveryChallan(): bool
    {
        return in_array($this->type, ['dc', 'return_dc'], true);
    }

    // Human label for lists/PDF headers.
    public function typeLabel(): string
    {
        return match ($this->type) {
            'dc'        => 'Delivery Challan',
            'return_dc' => 'Return Delivery Challan',
            default     => 'Stock Transfer',
        };
    }
}
