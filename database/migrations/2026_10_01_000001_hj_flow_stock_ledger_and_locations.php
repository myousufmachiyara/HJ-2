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
    /*
     * Written to be re-runnable: every step checks whether it was already done,
     * so a migration that stopped half-way (MySQL can't roll back DDL) can
     * simply be run again. Foreign keys are "best effort" — on servers where
     * tables are MyISAM, or an old key is missing, they are skipped instead of
     * failing the whole migration.
     */
    public function up(): void
    {
        // ── Locations ───────────────────────────────────────────────
        $this->addColumn('locations', 'type', fn (Blueprint $t) => $t->string('type', 20)->default('warehouse')->after('code'));
        $this->addColumn('locations', 'inventory_account_id', fn (Blueprint $t) => $t->unsignedBigInteger('inventory_account_id')->nullable()->after('chart_of_account_id'));
        $this->addForeign('locations', 'inventory_account_id', 'chart_of_accounts', 'set null');

        // ── Stock ledger ────────────────────────────────────────────
        if (!Schema::hasTable('stock_ledger')) {
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
            });
        }
        $this->addForeign('stock_ledger', 'product_id', 'products', 'cascade');
        $this->addForeign('stock_ledger', 'variation_id', 'product_variations', 'set null');
        $this->addForeign('stock_ledger', 'location_id', 'locations', 'cascade');

        // ── Products ────────────────────────────────────────────────
        // subcategory_id used to point at product_categories — repoint it to product_subcategories.
        $this->dropForeignIfExists('products', 'subcategory_id');
        DB::table('products')
            ->whereNotNull('subcategory_id')
            ->whereNotIn('subcategory_id', DB::table('product_subcategories')->pluck('id'))
            ->update(['subcategory_id' => null]);
        $this->addForeign('products', 'subcategory_id', 'product_subcategories', 'set null');

        $this->addColumn('products', 'fabric_id', fn (Blueprint $t) => $t->unsignedBigInteger('fabric_id')->nullable()->after('consumption'));
        $this->addForeign('products', 'fabric_id', 'products', 'set null');

        // ── Purchase invoice drop-off ───────────────────────────────
        $this->addColumn('purchase_invoices', 'dropoff_location_id', fn (Blueprint $t) => $t->unsignedBigInteger('dropoff_location_id')->nullable()->after('vendor_id'));
        $this->addForeign('purchase_invoices', 'dropoff_location_id', 'locations', 'set null');

        // ── FG receiving ────────────────────────────────────────────
        $this->addColumn('production_receivings', 'location_id', fn (Blueprint $t) => $t->unsignedBigInteger('location_id')->nullable()->after('vendor_id'));
        $this->addForeign('production_receivings', 'location_id', 'locations', 'set null');

        $this->addColumn('production_receiving_details', 'fabric_id', fn (Blueprint $t) => $t->unsignedBigInteger('fabric_id')->nullable()->after('variation_id'));
        $this->addColumn('production_receiving_details', 'fabric_qty', fn (Blueprint $t) => $t->decimal('fabric_qty', 15, 3)->default(0)->after('fabric_id'));
        $this->addColumn('production_receiving_details', 'fabric_rate', fn (Blueprint $t) => $t->decimal('fabric_rate', 15, 4)->default(0)->after('fabric_qty'));
        $this->addForeign('production_receiving_details', 'fabric_id', 'products', 'set null');

        // ── Frozen costs on movements / sales ───────────────────────
        $this->addColumn('stock_transfer_details', 'unit_cost', fn (Blueprint $t) => $t->decimal('unit_cost', 15, 4)->default(0)->after('quantity'));

        $this->addColumn('sale_invoices', 'location_id', fn (Blueprint $t) => $t->unsignedBigInteger('location_id')->nullable()->after('account_id'));
        $this->addForeign('sale_invoices', 'location_id', 'locations', 'set null');
        $this->addColumn('sale_invoice_items', 'unit_cost', fn (Blueprint $t) => $t->decimal('unit_cost', 15, 4)->default(0)->after('quantity'));

        $this->addColumn('sale_returns', 'location_id', fn (Blueprint $t) => $t->unsignedBigInteger('location_id')->nullable()->after('account_id'));
        $this->addForeign('sale_returns', 'location_id', 'locations', 'set null');
        $this->addColumn('sale_return_items', 'unit_cost', fn (Blueprint $t) => $t->decimal('unit_cost', 15, 4)->default(0)->after('price'));

        // System accounts, default warehouse, one location per customer/vendor,
        // an inventory account per location, new permissions. Same routine is
        // re-run at the end of DatabaseSeeder and by `php artisan hj:setup`.
        \App\Support\HjSetup::run();
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function addColumn(string $table, string $column, callable $definition): void
    {
        if (!Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $t) => $definition($t));
        }
    }

    /** Name of the FK on $table.$column, or null. */
    private function foreignKeyName(string $table, string $column): ?string
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            return null;
        }
        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
              LIMIT 1',
            [$table, $column]
        );
        return $row->name ?? null;
    }

    private function dropForeignIfExists(string $table, string $column): void
    {
        if ($name = $this->foreignKeyName($table, $column)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign($name));
        }
    }

    private function addForeign(string $table, string $column, string $on, string $onDelete): void
    {
        if ($this->foreignKeyName($table, $column)) {
            return; // already there
        }
        try {
            Schema::table($table, fn (Blueprint $t) => $t->foreign($column)->references('id')->on($on)->onDelete($onDelete));
        } catch (\Throwable $e) {
            // e.g. MyISAM tables or mismatched column types — the app does not depend on it
            \Illuminate\Support\Facades\Log::warning("[HJ migration] foreign key {$table}.{$column} skipped: " . $e->getMessage());
        }
    }

    public function down(): void
    {
        foreach ([
            ['sale_returns', 'location_id'], ['sale_invoices', 'location_id'], ['production_receiving_details', 'fabric_id'],
            ['production_receivings', 'location_id'], ['purchase_invoices', 'dropoff_location_id'], ['products', 'fabric_id'],
            ['locations', 'inventory_account_id'],
        ] as [$t, $c]) {
            $this->dropForeignIfExists($t, $c);
        }

        Schema::table('sale_return_items', fn (Blueprint $t) => $t->dropColumn('unit_cost'));
        Schema::table('sale_returns', function (Blueprint $t) { $t->dropColumn('location_id'); });
        Schema::table('sale_invoice_items', fn (Blueprint $t) => $t->dropColumn('unit_cost'));
        Schema::table('sale_invoices', function (Blueprint $t) { $t->dropColumn('location_id'); });
        Schema::table('stock_transfer_details', fn (Blueprint $t) => $t->dropColumn('unit_cost'));
        Schema::table('production_receiving_details', function (Blueprint $t) {
            $t->dropColumn(['fabric_id', 'fabric_qty', 'fabric_rate']);
        });
        Schema::table('production_receivings', function (Blueprint $t) { $t->dropColumn('location_id'); });
        Schema::table('purchase_invoices', function (Blueprint $t) { $t->dropColumn('dropoff_location_id'); });
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn('fabric_id');
        });
        Schema::dropIfExists('stock_ledger');
        Schema::table('locations', function (Blueprint $t) {
            $t->dropColumn(['type', 'inventory_account_id']);
        });
    }
};
