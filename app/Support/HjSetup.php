<?php

namespace App\Support;

use App\Models\ChartOfAccounts;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Idempotent setup for the HJ flow (safe to run any number of times):
 *   - system accounts used by the new postings
 *   - a default warehouse
 *   - one stock location per customer / vendor account
 *   - one inventory account per location
 *   - permissions for the new modules
 *
 * Runs from the migration, at the end of DatabaseSeeder and via `php artisan hj:setup`.
 */
class HjSetup
{
    public const SYSTEM_ACCOUNTS = [
        // code,     sub-head name,        fallback shoa id, name,                                 type
        ['104001', 'Inventory',           4,  'Stock in Hand',                         'inventory'],
        ['207010', 'Other Liabilities',   8,  'Post-Dated Cheques Payable',            'liability'],
        ['301010', 'Owner Capital',       9,  'Opening Stock Equity',                  'equity'],
        ['502010', 'Operating Expenses',  14, 'Stock Adjustment (Shortage / Excess)',  'expenses'],
    ];

    public const MODULES = ['stock_adjustments', 'pdc_cheques'];

    public static function run(): void
    {
        $userId = User::orderBy('id')->value('id');
        if (!$userId || !Schema::hasTable('sub_head_of_accounts') || !DB::table('sub_head_of_accounts')->exists()) {
            return; // fresh install — DatabaseSeeder calls us again once base data exists
        }

        foreach (self::SYSTEM_ACCOUNTS as [$code, $shoaName, $shoaFallback, $name, $type]) {
            if (ChartOfAccounts::withTrashed()->where('account_code', $code)->exists()) continue;
            $shoaId = DB::table('sub_head_of_accounts')->where('name', $shoaName)->value('id') ?? $shoaFallback;
            if (!DB::table('sub_head_of_accounts')->where('id', $shoaId)->exists()) continue;

            ChartOfAccounts::create([
                'account_code' => $code, 'shoa_id' => $shoaId, 'name' => $name, 'account_type' => $type,
                'receivables' => 0, 'payables' => 0, 'credit_limit' => 0,
                'opening_date' => now()->toDateString(),
                'created_by' => $userId, 'updated_by' => $userId,
            ]);
        }

        // Default warehouse
        if (!Location::warehouses()->exists()) {
            Location::create(['name' => 'Main Warehouse', 'code' => 'WH-MAIN', 'type' => Location::WAREHOUSE, 'is_default' => true]);
        } elseif (!Location::warehouses()->where('is_default', true)->exists()) {
            Location::warehouses()->orderBy('id')->first()->makeDefault();
        }

        Location::syncAll();

        // Fabric width attribute — fabrics get one variation per panna (e.g. 36", 44", 54", 58").
        // Rename it from Products → Attributes once the client confirms the term.
        if (Schema::hasTable('attributes') && !DB::table('attributes')->where('slug', 'panna')->exists()) {
            DB::table('attributes')->insert(['name' => 'PANNA', 'slug' => 'panna', 'created_at' => now(), 'updated_at' => now()]);
        }

        // Permissions
        if (Schema::hasTable('permissions')) {
            $names = [];
            foreach (self::MODULES as $module) {
                foreach (['index', 'create', 'edit', 'delete', 'print'] as $action) {
                    $names[] = "$module.$action";
                }
            }
            $names[] = 'reports.location_stock';

            foreach ($names as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
            if ($super = Role::where('name', 'superadmin')->first()) {
                $super->givePermissionTo($names);
            }
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
