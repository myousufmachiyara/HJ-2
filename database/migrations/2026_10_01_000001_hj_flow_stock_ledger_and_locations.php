<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HJ flow changes (Oct 2026)
 *
 *  - Locations get a type (warehouse / customer / vendor) and an inventory
 *    account so stock held at each place is valued in the ledger.
 *  - stock_ledger: one signed row per item movement per location. This is the
 *    single source of truth for "how many pieces are WHERE".
 *  - Purchase invoice drop-off location, FG receiving fabric consumption,
 *    sale / sale-return location, frozen unit costs.
 *  - Product -> fabric link (for auto fabric consumption at CMT).
 *  - Fix: products.subcategory_id pointed at product_categories.
 *  - System accounts needed by the new postings.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Locations ───────────────────────────────────────────────
        Schema::table('locations', function (Blueprint $table) {
            $table->string('type', 20)->default('warehouse')->after('code'); // warehouse | customer | vendor
            $table->unsignedBigInteger('inventory_account_id')->nullable()->after('chart_of_account_id');
            $table->foreign('inventory_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
        });

        // ── Stock ledger ────────────────────────────────────────────
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->unsignedBigInteger('location_id');
            $table->decimal('qty', 15, 3);                 // + in, − out
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'variation_id', 'location_id'], 'stock_ledger_item_loc_idx');
            $table->index(['source_type', 'source_id'], 'stock_ledger_source_idx');
            $table->index('date');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('variation_id')->references('id')->on('product_variations')->nullOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
        });

        // ── Products ────────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['subcategory_id']);
        });
        // Old FK allowed category ids in subcategory_id — null anything that is not a real subcategory.
        DB::table('products')
            ->whereNotNull('subcategory_id')
            ->whereNotIn('subcategory_id', DB::table('product_subcategories')->pluck('id'))
            ->update(['subcategory_id' => null]);
        Schema::table('products', function (Blueprint $table) {
            $table->foreign('subcategory_id')->references('id')->on('product_subcategories')->nullOnDelete();
            $table->unsignedBigInteger('fabric_id')->nullable()->after('consumption');
            $table->foreign('fabric_id')->references('id')->on('products')->nullOnDelete();
        });

        // ── Purchase invoice drop-off ───────────────────────────────
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('dropoff_location_id')->nullable()->after('vendor_id');
            $table->foreign('dropoff_location_id')->references('id')->on('locations')->nullOnDelete();
        });

        // ── FG receiving ────────────────────────────────────────────
        Schema::table('production_receivings', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('vendor_id'); // receiving warehouse
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
        });
        Schema::table('production_receiving_details', function (Blueprint $table) {
            $table->unsignedBigInteger('fabric_id')->nullable()->after('variation_id');
            $table->decimal('fabric_qty', 15, 3)->default(0)->after('fabric_id');
            $table->decimal('fabric_rate', 15, 4)->default(0)->after('fabric_qty');
            $table->foreign('fabric_id')->references('id')->on('products')->nullOnDelete();
        });

        // ── Frozen costs on movements / sales ───────────────────────
        Schema::table('stock_transfer_details', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->default(0)->after('quantity');
        });
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('account_id');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
        });
        Schema::table('sale_invoice_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->default(0)->after('quantity');
        });
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('account_id');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
        });
        Schema::table('sale_return_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->default(0)->after('price');
        });

        // System accounts, default warehouse, one location per customer/vendor,
        // an inventory account per location, new permissions. Same routine is
        // re-run at the end of DatabaseSeeder and by `php artisan hj:setup`.
        \App\Support\HjSetup::run();
    }

    public function down(): void
    {
        Schema::table('sale_return_items', fn (Blueprint $t) => $t->dropColumn('unit_cost'));
        Schema::table('sale_returns', function (Blueprint $t) { $t->dropForeign(['location_id']); $t->dropColumn('location_id'); });
        Schema::table('sale_invoice_items', fn (Blueprint $t) => $t->dropColumn('unit_cost'));
        Schema::table('sale_invoices', function (Blueprint $t) { $t->dropForeign(['location_id']); $t->dropColumn('location_id'); });
        Schema::table('stock_transfer_details', fn (Blueprint $t) => $t->dropColumn('unit_cost'));
        Schema::table('production_receiving_details', function (Blueprint $t) {
            $t->dropForeign(['fabric_id']); $t->dropColumn(['fabric_id', 'fabric_qty', 'fabric_rate']);
        });
        Schema::table('production_receivings', function (Blueprint $t) { $t->dropForeign(['location_id']); $t->dropColumn('location_id'); });
        Schema::table('purchase_invoices', function (Blueprint $t) { $t->dropForeign(['dropoff_location_id']); $t->dropColumn('dropoff_location_id'); });
        Schema::table('products', function (Blueprint $t) {
            $t->dropForeign(['fabric_id']); $t->dropColumn('fabric_id');
            $t->dropForeign(['subcategory_id']);
        });
        Schema::dropIfExists('stock_ledger');
        Schema::table('locations', function (Blueprint $t) {
            $t->dropForeign(['inventory_account_id']); $t->dropColumn(['type', 'inventory_account_id']);
        });
    }
};
