<?php
// app/Models/PurchaseReturnAttachment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnAttachment extends Model
{
    protected $fillable = ['purchase_return_id', 'file_path', 'original_name', 'file_type'];
}