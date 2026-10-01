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
            ['name' => '3pc Kurta Trouser',    'code' => '3KT'],
            ['name' => '3pc Prince Coat',      'code' => '3PC'],
            ['name' => '3pc Sherwani',         'code' => '3SH'],
            ['name' => 'Waistcoat',            'code' => 'WC'],
            ['name' => 'Sherwani',             'code' => 'SH'],
            ['name' => 'Plain Kurta Trouser',  'code' => 'PKT'],
            ['name' => 'Design Kurta Trouser', 'code' => 'DKT'],
            ['name' => 'Prince Coat',          'code' => 'PC'],
            ['name' => '5pcs Suit',            'code' => '5S'],
            ['name' => '4pcs Suit',            'code' => '4S'],
            ['name' => '3pcs Suit',            'code' => '3S'],

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
