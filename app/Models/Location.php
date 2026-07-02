<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'code', 'is_default', 'chart_of_account_id'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

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

    // ── Scopes ────────────────────────────────────────────────────────
    public function scopeWarehouses($query)
    {
        return $query->whereNull('chart_of_account_id');
    }

    public function scopeCustomers($query)
    {
        return $query->whereNotNull('chart_of_account_id');
    }

    /**
     * The default warehouse — where all untransferred stock (opening, purchases,
     * production receipts) is considered to live. Falls back to the first
     * warehouse if no explicit default is set, so reports never break.
     */
    public static function default(): ?self
    {
        return static::warehouses()->where('is_default', true)->first()
            ?? static::warehouses()->orderBy('id')->first();
    }

    public static function defaultId(): ?int
    {
        return static::default()?->id;
    }

    /**
     * Make this location the one-and-only default warehouse.
     * Clears the flag on every other location first. Customers can't be default.
     */
    public function makeDefault(): bool
    {
        if ($this->isCustomer()) {
            return false; // a customer location can never be the default warehouse
        }

        static::query()->where('is_default', true)->update(['is_default' => false]);
        $this->is_default = true;
        return $this->save();
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
        // is_default stays false for customers (default DB value / cast)
        $location->save();

        return $location;
    }
}