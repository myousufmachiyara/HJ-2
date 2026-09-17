<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX (security/functionality review):
 * ProcessShopifyImport::syncProduct() has always matched/written
 * `shopify_product_id` and `shopify_store_id` on the `products` table,
 * but those columns never existed. Every sync was crashing on the very
 * first product with "Unknown column 'shopify_product_id' in where clause".
 *
 * ProcessShopifyImport::syncVariant() also writes `selling_price` on
 * `product_variations`, which doesn't have that column — Eloquent
 * silently drops it (not fillable), so variant-level prices from
 * Shopify were being discarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('shopify_store_id')
                ->nullable()
                ->after('id')
                ->constrained('shopify_stores')
                ->nullOnDelete();

            $table->string('shopify_product_id')->nullable()->after('shopify_store_id');

            // A given Shopify product can only map to one local product per store.
            // (NULLs are allowed to repeat — manually created products are unaffected.)
            $table->unique(
                ['shopify_store_id', 'shopify_product_id'],
                'products_shopify_store_product_unique'
            );
        });

        Schema::table('product_variations', function (Blueprint $table) {
            $table->decimal('selling_price', 10, 2)->nullable()->after('stock_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_shopify_store_product_unique');
            $table->dropConstrainedForeignId('shopify_store_id');
            $table->dropColumn('shopify_product_id');
        });

        Schema::table('product_variations', function (Blueprint $table) {
            $table->dropColumn('selling_price');
        });
    }
};
