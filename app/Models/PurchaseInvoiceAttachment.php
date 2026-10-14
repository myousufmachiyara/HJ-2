<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseInvoiceAttachment extends Model
{
    protected $fillable = [
        'purchase_invoice_id',
        'file_path',
        'file_name',
        'original_name',
        'file_type',
    ];

    public function invoice()
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }
}
