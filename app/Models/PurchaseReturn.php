<?php
// app/Models/PurchaseReturn.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseReturn extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vendor_id', 'return_date', 'return_no', 'ref_no',
        'bill_no', 'remarks', 'convance_charges', 'bill_discount', 'created_by',
    ];

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id');
    }

    public function items()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function attachments()
    {
        return $this->hasMany(PurchaseReturnAttachment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}