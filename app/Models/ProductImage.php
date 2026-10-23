<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'image_path',
    ];

    /**
     * Address of the picture. Served through the app (route product-image.show) so it works
     * even where public/storage is not linked (php artisan storage:link missing / not allowed
     * on the hosting). Full http(s) links are used as they are.
     */
    public function getUrlAttribute(): string
    {
        $path = (string) $this->image_path;
        if (preg_match('#^https?://#i', $path)) return $path;
        return route('product-image.show', ['image' => $this->id, 'v' => substr(md5($path), 0, 8)]);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
