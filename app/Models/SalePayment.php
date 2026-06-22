<?php
// app/Models/SalePayment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    protected $fillable = [
        'sale_invoice_id', 'account_id', 'payment_date',
        'amount', 'reference', 'remarks', 'created_by',
    ];

    public function invoice() { return $this->belongsTo(SaleInvoice::class); }
    public function account() { return $this->belongsTo(ChartOfAccounts::class, 'account_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}