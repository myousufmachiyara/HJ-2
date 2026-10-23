<?php

namespace App\Http\Controllers;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

/** Streams a product picture from storage/app/public (no public/storage link needed). */
class ProductImageController extends Controller
{
    public function show(ProductImage $image)
    {
        $path = ltrim(str_replace(['..', '\\'], ['', '/'], (string) $image->image_path), '/');
        $path = preg_replace('#^(public/|storage/)#', '', $path);   // older rows saved with a prefix
        $disk = Storage::disk('public');
        abort_unless($path !== '' && $disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
