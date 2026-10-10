<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

/**
 * Hassan Jee product categories.
 *
 * The CODE is the SKU prefix: products get {CODE}-{00001}, e.g. 3KT-00001,
 * and variations add the size, e.g. 3KT-00001-M. Keep codes short,
 * letters/digits only, and never change a code once products exist.
 *
 * Safe to run again: php artisan db:seed --class=ProductCategorySeeder
 */
class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Waist Coat Suit',  'code' => 'WS'],
            ['name' => 'Prince Coat Suit', 'code' => 'PS'],
            ['name' => 'Sherwani Set',     'code' => 'SS'],
            ['name' => 'Waistcoat',        'code' => 'W'],
            ['name' => 'Prince Coat',      'code' => 'P'],
            ['name' => 'Sherwani',         'code' => 'S'],
            ['name' => 'Kurta Set',        'code' => 'KS'],
            ['name' => 'Kurta',            'code' => 'K'],
            ['name' => '5pcs Coat Pent',   'code' => '5C'],
            ['name' => '4pcs Coat Pent',   'code' => '4C'],
            ['name' => '3pcs Pant Shirt',  'code' => '3P'],

            // Raw material — fabric purchased and dropped at CMT (item type "raw")
            ['name' => 'Fabric',               'code' => 'FAB'],
        ];

        foreach ($categories as $cat) {
            $row = ProductCategory::withTrashed()->updateOrCreate(['code' => $cat['code']], $cat);
            if ($row->trashed()) $row->restore();
        }

        $this->command?->info('Product categories: ' . count($categories) . ' in place.');
    }
}
