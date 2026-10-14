<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionReceivingAttachment extends Model
{
    protected $fillable = ['production_receiving_id', 'file_path', 'original_name', 'file_type'];

    public function receiving()
    {
        return $this->belongsTo(ProductionReceiving::class, 'production_receiving_id');
    }
}
