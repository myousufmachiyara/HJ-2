<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'code', 'chart_of_account_id'];

    public function stockTransfersFrom()
    {
        return $this->hasMany(StockTransfer::class, 'from_location_id');
    }

    public function stockTransfersTo()
    {
        return $this->hasMany(StockTransfer::class, 'to_location_id');
    }

    // The customer account this location holds stock for (null = physical warehouse).
    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'chart_of_account_id');
    }

    // True when this location represents a customer's held stock.
    public function isCustomer(): bool
    {
        return !is_null($this->chart_of_account_id);
    }

    // Scope: only physical warehouse locations (the ones users manage by hand).
    public function scopeWarehouses($query)
    {
        return $query->whereNull('chart_of_account_id');
    }

    // Scope: only customer stock-holder locations.
    public function scopeCustomers($query)
    {
        return $query->whereNotNull('chart_of_account_id');
    }

    /**
     * Ensure a stock-holder location exists for a given customer account.
     * Called whenever a customer account is created/updated. Idempotent.
     */
    public static function syncForCustomer(ChartOfAccounts $account): ?self
    {
        if ($account->account_type !== 'customer') {
            return null;
        }

        $location = static::firstOrNew(['chart_of_account_id' => $account->id]);
        $location->name = 'Customer: ' . $account->name;
        $location->code = 'CUST-' . $account->account_code;
        $location->save();

        return $location;
    }
}