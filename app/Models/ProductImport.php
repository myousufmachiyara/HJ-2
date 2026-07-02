<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'delete_missing' => 'boolean',
    ];
}