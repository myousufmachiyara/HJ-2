<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaamInvoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_no', 'date', 'period_from', 'period_to', 'location_id', 'account_id', 'ref_no', 'file_name', 'remarks',
        'total_retail', 'total_realized', 'total_fee', 'total_pra_tax', 'total_sales_tax', 'total_income_tax',
        'total_adjustment', 'total_payable', 'created_by',
    ];

    protected $casts = ['date' => 'date', 'period_from' => 'date', 'period_to' => 'date'];

    public function items()    { return $this->hasMany(LaamInvoiceItem::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function account()  { return $this->belongsTo(ChartOfAccounts::class, 'account_id'); }
    public function creator()  { return $this->belongsTo(User::class, 'created_by'); }
}
