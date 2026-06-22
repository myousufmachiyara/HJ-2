<?php
// app/Models/SaleInvoice.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleInvoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_no', 'date', 'account_id', 'type', 'payment_terms',
        'ref_no', 'remarks', 'sub_total', 'discount', 'convance_charges',
        'net_amount', 'paid_amount', 'balance', 'payment_status', 'created_by',
    ];

    public function items()
    {
        return $this->hasMany(SaleInvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }

    public function account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'account_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recalculatePaymentStatus(): void
    {
        $paid = $this->payments()->sum('amount');
        $this->paid_amount     = $paid;
        $this->balance         = max(0, $this->net_amount - $paid);
        $this->payment_status  = $paid <= 0 ? 'unpaid'
            : ($paid >= $this->net_amount ? 'paid' : 'partial');
        $this->saveQuietly();
    }
}