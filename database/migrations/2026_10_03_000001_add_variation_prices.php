<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selling price and compare-at price per variation (size).
 * Blank on a variation = use the product's default price.
 * Safe to run more than once.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('product_variations', 'selling_price')) {
            Schema::table('product_variations', fn (Blueprint $t) => $t->decimal('selling_price', 10, 2)->nullable()->after('stock_quantity'));
        }
        if (!Schema::hasColumn('product_variations', 'compare_at_price')) {
            Schema::table('product_variations', fn (Blueprint $t) => $t->decimal('compare_at_price', 10, 2)->nullable()->after('selling_price'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_variations', 'compare_at_price')) {
            Schema::table('product_variations', fn (Blueprint $t) => $t->dropColumn('compare_at_price'));
        }
    }
};
