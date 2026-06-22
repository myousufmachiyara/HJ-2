<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionWastageReceiving extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'production_id',
        'vendor_id',
        'rec_date',
        'grn_no',
        'remarks',
        'received_by',
    ];

    public function production()
    {
        return $this->belongsTo(Production::class);
    }

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id');
    }

    public function details()
    {
        return $this->hasMany(ProductionWastageReceivingDetail::class, 'wastage_receiving_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}