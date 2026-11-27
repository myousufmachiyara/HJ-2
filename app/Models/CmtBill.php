<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmtBill extends Model
{
    use SoftDeletes;

    protected $fillable = ['bill_no', 'vendor_id', 'location_id', 'date_from', 'date_to', 'bill_date',
        'total_qty', 'cmt_amount', 'fabric_amount', 'remarks', 'created_by'];

    public function lines()   { return $this->hasMany(CmtBillLine::class); }
    public function vendor()  { return $this->belongsTo(ChartOfAccounts::class, 'vendor_id'); }
    public function details() { return $this->hasMany(ProductionReceivingDetail::class, 'cmt_bill_id'); }
}
