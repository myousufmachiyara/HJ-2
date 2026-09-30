<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChartOfAccounts extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'shoa_id',
        'name',
        'account_code',
        'account_type',
        'vendor_type',   // ← ADD
        'receivables',
        'payables',
        'credit_limit',
        'opening_date',
        'remarks',
        'address',
        'contact_no',
        'created_by',
        'updated_by',
    ];

    // Define the relationship with SubHeadOfAccounts (belongs to)
    public function subHeadOfAccount()
    {
        return $this->belongsTo(SubHeadOfAccounts::class, 'shoa_id', 'id');
    }

    /**
     * Next free account code for a sub-head (same scheme as COAController:
     * hoa_id + 2-digit shoa id + 3-digit running number).
     */
    public static function nextCode(int $shoaId): string
    {
        $subHead = SubHeadOfAccounts::findOrFail($shoaId);
        $prefix  = $subHead->hoa_id . str_pad($subHead->id, 2, '0', STR_PAD_LEFT);
        $max     = static::withTrashed()
            ->where('account_code', 'like', $prefix . '%')
            ->pluck('account_code')
            ->map(fn ($code) => (int) substr($code, strlen($prefix)))
            ->max() ?? 0;

        return $prefix . str_pad($max + 1, 3, '0', STR_PAD_LEFT);
    }

    public function location()
    {
        return $this->hasOne(Location::class, 'chart_of_account_id');
    }

    public function purchaseInvoices()
    {
        return $this->hasMany(PurchaseInvoice::class, 'vendor_id');
    }

}
