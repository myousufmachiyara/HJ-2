<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shopify's own id per size, so a size edited on Shopify updates the same variation. Safe to re-run. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('product_variations', 'shopify_variant_id')) {
            Schema::table('product_variations', function (Blueprint $t) {
                $t->string('shopify_variant_id', 40)->nullable()->after('product_id');
                $t->index('shopify_variant_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_variations', 'shopify_variant_id')) {
            Schema::table('product_variations', function (Blueprint $t) {
                $t->dropIndex(['shopify_variant_id']);
                $t->dropColumn('shopify_variant_id');
            });
        }
    }
};
