<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdcChequeBill extends Model
{
    public const TYPES = [
        'purchase'  => PurchaseInvoice::class,
        'receiving' => ProductionReceiving::class,
    ];

    protected $fillable = ['pdc_cheque_id', 'bill_type', 'bill_id', 'amount'];

    public function cheque() { return $this->belongsTo(PdcCheque::class, 'pdc_cheque_id'); }

    public function bill()
    {
        $class = self::TYPES[$this->bill_type] ?? null;
        return $class ? $class::withTrashed()->find($this->bill_id) : null;
    }

    public function billLabel(): string
    {
        $bill = $this->bill();
        if (!$bill) return ucfirst($this->bill_type) . ' #' . $this->bill_id;
        return $this->bill_type === 'purchase'
            ? $bill->invoice_no . ($bill->bill_no ? ' (Bill ' . $bill->bill_no . ')' : '')
            : $bill->grn_no;
    }
}
