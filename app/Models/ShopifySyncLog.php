<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifySyncLog extends Model
{
    protected $fillable = [
        'shopify_store_id', 
        'status', 
        'total_products', 
        'synced_products', 
        'failed_products', 
        'error_message'
    ];

    public function store()
    {
        return $this->belongsTo(ShopifyStore::class, 'shopify_store_id');
    }
}
