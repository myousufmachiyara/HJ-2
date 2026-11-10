<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the pages that filter / group by item and location.
 * Each one is added only when the table + columns exist and the index is not there yet.
 */
return new class extends Migration
{
    /** table => [index name => columns] */
    private array $indexes = [
        'products'            => ['products_type_active_shopify_idx' => ['item_type', 'is_active', 'shopify_product_id'],
                                  'products_brand_idx'               => ['brand']],
        'stock_ledger'        => ['stock_ledger_loc_item_idx'        => ['location_id', 'product_id', 'variation_id']],
        'sale_invoice_items'  => ['sale_items_product_variation_idx' => ['product_id', 'variation_id']],
        'laam_invoice_items'  => ['laam_items_product_variation_idx' => ['product_id', 'variation_id']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $list) {
            if (!Schema::hasTable($table)) continue;
            foreach ($list as $name => $cols) {
                if (!Schema::hasColumns($table, $cols) || Schema::hasIndex($table, $name)) continue;
                Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $list) {
            if (!Schema::hasTable($table)) continue;
            foreach (array_keys($list) as $name) {
                if (Schema::hasIndex($table, $name)) Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
