<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdcChequeLog extends Model
{
    protected $fillable = ['pdc_cheque_id', 'from_status', 'to_status', 'date', 'remarks', 'user_id'];
    protected $casts = ['date' => 'date'];

    public function user() { return $this->belongsTo(User::class); }
}
