<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseInvoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_no',
        'vendor_id',
        'invoice_date',
        'payment_terms',
        'bill_no',
        'ref_no',
        'remarks',
        'convance_charges',
        'labour_charges',
        'bill_discount',
        'created_by',
    ];

    // ── Auto-generate invoice_no before saving ────────────────────────
    protected static function booted(): void
    {
        static::creating(function (PurchaseInvoice $invoice) {
            if (empty($invoice->invoice_no)) {
                $invoice->invoice_no = static::generateInvoiceNo();
            }
        });
    }

    public static function generateInvoiceNo(): string
    {
        // Lock the row to prevent race conditions on concurrent requests
        $last = static::withTrashed()
            ->whereNotNull('invoice_no')
            ->where('invoice_no', 'like', 'PUR-%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('invoice_no');

        $next = 1;
        if ($last) {
            // Extract the numeric part after "PUR-"
            $numeric = (int) substr($last, 4);
            $next    = $numeric + 1;
        }

        return 'PUR-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    public function items()
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'purchase_invoice_id');
    }

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments()
    {
        return $this->hasMany(PurchaseInvoiceAttachment::class);
    }

    public function voucher()
    {
        return $this->morphOne(Voucher::class, 'source');
    }
}