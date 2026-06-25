<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\HeadOfAccounts;
use App\Models\SubHeadOfAccounts;
use App\Models\ChartOfAccounts;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\MeasurementUnit;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\BarcodeSequence;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $now = now();
        $userId = 1;
        // 🔑 Create Super Admin User
        $admin = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Admin',
                'email' => 'admin@gmail.com', // optional, keep if you want for notifications
                'password' => Hash::make('12345678'),
            ]
        );

        $superAdmin = Role::firstOrCreate(['name' => 'superadmin']);
        $admin->assignRole($superAdmin);

        // 📌 Functional Modules (CRUD-style permissions)
        $modules = [
            // User Management
            'user_roles',
            'users',

            // Accounts
            'coa',
            'shoa',

            // Products
            'products',
            'product_categories',
            'product_subcategories',
            'attributes',
            'shopify_stores', // 👈 Add this

            // Stock Management
            'locations',
            'stock_transfer',

            // Purchases
            'purchase_invoices',
            'purchase_return',

            // Sales
            'sale_invoices',
            'sale_return',

            // Vouchers
            'vouchers',

            // Production
            'production',
            'production_receiving',
            'production_return',
            'production_wastage',

            // POS
            'pos_system',
        ];

        $actions = ['index', 'create', 'edit', 'delete', 'print'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "$module.$action",
                ]);
            }
        }

        // 📊 Report permissions (only view access, no CRUD)
        $reports = ['inventory', 'purchase', 'production', 'sales', 'accounts'];

        foreach ($reports as $report) {
            Permission::firstOrCreate([
                'name' => "reports.$report",
            ]);
        }

        // Assign all permissions to Superadmin
        $superAdmin->syncPermissions(Permission::all());

        // ---------------------
        // HEADS OF ACCOUNTS
        // ---------------------
        HeadOfAccounts::insert([
            ['id' => 1, 'name' => 'Assets',      'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Liabilities', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Equity',      'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'Revenue',     'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'name' => 'Expenses',    'created_at' => $now, 'updated_at' => $now],
        ]);

        // ---------------------
        // SUB HEADS
        // ---------------------
        SubHeadOfAccounts::insert([
            // Assets
            ['id' => 1,  'hoa_id' => 1, 'name' => 'Cash & Cash Equivalents', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2,  'hoa_id' => 1, 'name' => 'Bank Accounts',           'created_at' => $now, 'updated_at' => $now],
            ['id' => 3,  'hoa_id' => 1, 'name' => 'Accounts Receivable',     'created_at' => $now, 'updated_at' => $now],
            ['id' => 4,  'hoa_id' => 1, 'name' => 'Inventory',               'created_at' => $now, 'updated_at' => $now],
            ['id' => 5,  'hoa_id' => 1, 'name' => 'Other Current Assets',    'created_at' => $now, 'updated_at' => $now],

            // Liabilities
            ['id' => 6,  'hoa_id' => 2, 'name' => 'Accounts Payable',        'created_at' => $now, 'updated_at' => $now],
            ['id' => 7,  'hoa_id' => 2, 'name' => 'Loans & Borrowings',      'created_at' => $now, 'updated_at' => $now],
            ['id' => 8,  'hoa_id' => 2, 'name' => 'Other Liabilities',       'created_at' => $now, 'updated_at' => $now],

            // Equity
            ['id' => 9,  'hoa_id' => 3, 'name' => 'Owner Capital',           'created_at' => $now, 'updated_at' => $now],
            ['id' => 10, 'hoa_id' => 3, 'name' => 'Retained Earnings',       'created_at' => $now, 'updated_at' => $now],

            // Revenue
            ['id' => 11, 'hoa_id' => 4, 'name' => 'Sales Revenue',           'created_at' => $now, 'updated_at' => $now],
            ['id' => 12, 'hoa_id' => 4, 'name' => 'Other Income',            'created_at' => $now, 'updated_at' => $now],

            // Expenses
            ['id' => 13, 'hoa_id' => 5, 'name' => 'Cost of Goods Sold',      'created_at' => $now, 'updated_at' => $now],
            ['id' => 14, 'hoa_id' => 5, 'name' => 'Operating Expenses',      'created_at' => $now, 'updated_at' => $now],
            ['id' => 15, 'hoa_id' => 5, 'name' => 'Salaries & Wages',        'created_at' => $now, 'updated_at' => $now],
            ['id' => 16, 'hoa_id' => 5, 'name' => 'Production Expenses',     'created_at' => $now, 'updated_at' => $now],
        ]);

        // ---------------------
        // CHART OF ACCOUNTS
        // ---------------------
        $coaData = [

            // ── ASSETS ──────────────────────────────────────────────────────
            // Cash
            ['account_code' => '101001', 'shoa_id' => 1,  'name' => 'Shop Cash',              'account_type' => 'cash',     'receivables' => 0, 'payables' => 0],
            ['account_code' => '101002', 'shoa_id' => 1,  'name' => 'Petty Cash',             'account_type' => 'cash',     'receivables' => 0, 'payables' => 0],

            // Bank
            ['account_code' => '102001', 'shoa_id' => 2,  'name' => 'Meezan Bank',            'account_type' => 'bank',     'receivables' => 0, 'payables' => 0],
            ['account_code' => '102002', 'shoa_id' => 2,  'name' => 'HBL Account',            'account_type' => 'bank',     'receivables' => 0, 'payables' => 0],

            // Inventory
            ['account_code' => '104001', 'shoa_id' => 4,  'name' => 'Stock in Hand',          'account_type' => 'asset',    'receivables' => 0, 'payables' => 0],
            ['account_code' => '104002', 'shoa_id' => 4,  'name' => 'Raw Material Stock',     'account_type' => 'asset',    'receivables' => 0, 'payables' => 0],
            ['account_code' => '104003', 'shoa_id' => 4,  'name' => 'Work In Progress',       'account_type' => 'asset',    'receivables' => 0, 'payables' => 0],
            ['account_code' => '104004', 'shoa_id' => 4,  'name' => 'Finished Goods Stock',   'account_type' => 'asset',    'receivables' => 0, 'payables' => 0],

            // Other Current Assets
            ['account_code' => '105001', 'shoa_id' => 5,  'name' => 'Advance to Suppliers',   'account_type' => 'asset',    'receivables' => 0, 'payables' => 0],
            ['account_code' => '105002', 'shoa_id' => 5,  'name' => 'Prepaid Expenses',       'account_type' => 'asset',    'receivables' => 0, 'payables' => 0],
            ['account_code' => '105003', 'shoa_id' => 5,  'name' => 'Security Deposits',      'account_type' => 'asset',    'receivables' => 0, 'payables' => 0],

            // ── LIABILITIES ─────────────────────────────────────────────────

            // Loans
            ['account_code' => '206001', 'shoa_id' => 7,  'name' => 'Bank Loan',              'account_type' => 'liability','receivables' => 0, 'payables' => 0],

            // Other Liabilities
            ['account_code' => '207001', 'shoa_id' => 8,  'name' => 'Salaries Payable',       'account_type' => 'liability','receivables' => 0, 'payables' => 0],
            ['account_code' => '207002', 'shoa_id' => 8,  'name' => 'Tax Payable',            'account_type' => 'liability','receivables' => 0, 'payables' => 0],
            ['account_code' => '207003', 'shoa_id' => 8,  'name' => 'Advance from Customers', 'account_type' => 'liability','receivables' => 0, 'payables' => 0],

            // ── EQUITY ──────────────────────────────────────────────────────
            ['account_code' => '301001', 'shoa_id' => 9,  'name' => 'Owners Equity',          'account_type' => 'equity',   'receivables' => 0, 'payables' => 0],
            ['account_code' => '302001', 'shoa_id' => 10, 'name' => 'Retained Earnings',      'account_type' => 'equity',   'receivables' => 0, 'payables' => 0],

            // ── REVENUE ─────────────────────────────────────────────────────
            ['account_code' => '401001', 'shoa_id' => 11, 'name' => 'Sales Revenue',          'account_type' => 'revenue',  'receivables' => 0, 'payables' => 0],
            ['account_code' => '401002', 'shoa_id' => 11, 'name' => 'Sales Return',           'account_type' => 'revenue',  'receivables' => 0, 'payables' => 0], // contra
            ['account_code' => '401003', 'shoa_id' => 11, 'name' => 'Sales Discount',         'account_type' => 'revenue',  'receivables' => 0, 'payables' => 0], // contra
            ['account_code' => '402001', 'shoa_id' => 12, 'name' => 'Purchase Discount',      'account_type' => 'revenue',  'receivables' => 0, 'payables' => 0], // discount received from vendor
            ['account_code' => '402002', 'shoa_id' => 12, 'name' => 'Other Income',           'account_type' => 'revenue',  'receivables' => 0, 'payables' => 0],

            // ── EXPENSES ────────────────────────────────────────────────────
            // COGS
            ['account_code' => '501001', 'shoa_id' => 13, 'name' => 'Cost of Goods Sold',     'account_type' => 'cogs',     'receivables' => 0, 'payables' => 0],
            ['account_code' => '501002', 'shoa_id' => 13, 'name' => 'Purchase Return',        'account_type' => 'cogs',     'receivables' => 0, 'payables' => 0], // contra

            // Operating Expenses
            ['account_code' => '502001', 'shoa_id' => 14, 'name' => 'Conveyance Expense',     'account_type' => 'expense',  'receivables' => 0, 'payables' => 0],
            ['account_code' => '502002', 'shoa_id' => 14, 'name' => 'Labour Expense',         'account_type' => 'expense',  'receivables' => 0, 'payables' => 0],
            ['account_code' => '502003', 'shoa_id' => 14, 'name' => 'Rent Expense',           'account_type' => 'expense',  'receivables' => 0, 'payables' => 0],
            ['account_code' => '502004', 'shoa_id' => 14, 'name' => 'Utilities Expense',      'account_type' => 'expense',  'receivables' => 0, 'payables' => 0],
            ['account_code' => '502005', 'shoa_id' => 14, 'name' => 'Repair & Maintenance',   'account_type' => 'expense',  'receivables' => 0, 'payables' => 0],
            ['account_code' => '502006', 'shoa_id' => 14, 'name' => 'Miscellaneous Expense',  'account_type' => 'expense',  'receivables' => 0, 'payables' => 0],

            // Salaries
            ['account_code' => '503001', 'shoa_id' => 15, 'name' => 'Salaries Expense',       'account_type' => 'expense',  'receivables' => 0, 'payables' => 0],

            // Production Expenses
            ['account_code' => '504001', 'shoa_id' => 16, 'name' => 'Production Labour',      'account_type' => 'expense',  'receivables' => 0, 'payables' => 0],
            ['account_code' => '504002', 'shoa_id' => 16, 'name' => 'Production Overhead',    'account_type' => 'expense',  'receivables' => 0, 'payables' => 0],
            ['account_code' => '504003', 'shoa_id' => 16, 'name' => 'Raw Material Consumed',  'account_type' => 'expense',  'receivables' => 0, 'payables' => 0],
        ];

        foreach ($coaData as $data) {
            ChartOfAccounts::create(array_merge($data, [
                'opening_date' => now()->toDateString(),
                'credit_limit' => 0.00,
                'remarks'      => null,
                'address'      => null,
                'contact_no'     => null,
                'created_by'   => $userId,
                'updated_by'   => $userId,
            ]));
        }

        Attribute::insert([
            ['id' => 1, 'name' => 'SIZE',           'slug' => 'size',           'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'AGE',            'slug' => 'age',            'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now]
        ]);

       AttributeValue::insert([
            // ── SIZE (attribute_id = 1) ───────────────────────────────────────
            ['id' => 1,   'attribute_id' => 1, 'value' => 'XXS',                   'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2,   'attribute_id' => 1, 'value' => 'XS',                    'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3,   'attribute_id' => 1, 'value' => 'S',                     'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4,   'attribute_id' => 1, 'value' => 'M',                     'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5,   'attribute_id' => 1, 'value' => 'L',                     'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 6,   'attribute_id' => 1, 'value' => 'XL',                    'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 7,   'attribute_id' => 1, 'value' => 'XXL',                   'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 8,   'attribute_id' => 1, 'value' => 'XXXL',                  'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],

            // ── AGE (attribute_id = 2) ──────────────────────────────────────
            ['id' => 9,   'attribute_id' => 2, 'value' => '0M-3M',                 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 10,  'attribute_id' => 2, 'value' => '3M-6M',                 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 11,  'attribute_id' => 2, 'value' => '6M-9M',                 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 12,  'attribute_id' => 2, 'value' => '9M-12M',                'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 13,  'attribute_id' => 2, 'value' => '1-2Y',                 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 14,  'attribute_id' => 2, 'value' => '2-3Y',                 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 15,  'attribute_id' => 2, 'value' => '3-4Y',                 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 16,  'attribute_id' => 2, 'value' => '4-5Y',                 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 17,  'attribute_id' => 2, 'value' => '5-6Y',                 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 18,  'attribute_id' => 2, 'value' => '6-7Y',                 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 19,  'attribute_id' => 2, 'value' => '7-8Y',                 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 20,  'attribute_id' => 2, 'value' => '8-9Y',                 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 21,  'attribute_id' => 2, 'value' => '9-10Y',                'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 22,  'attribute_id' => 2, 'value' => '10-11Y',               'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 23,  'attribute_id' => 2, 'value' => '11-12Y',               'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 24,  'attribute_id' => 2, 'value' => '12-13Y',               'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 25,  'attribute_id' => 2, 'value' => '13-14Y',               'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);


        /*
        |--------------------------------------------------------------------------
        | Product Categories
        |--------------------------------------------------------------------------
        */

        $categories = [
            ['id' => 1,  'name' => 'Default',         'code' => 'DEFAULT'],
        ];

        foreach ($categories as $cat) {
            ProductCategory::firstOrCreate(
                ['code' => $cat['code']],
                array_merge($cat, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
       
        // 📏 Measurement Units
        MeasurementUnit::insert([
            ['id' => 1, 'name' => 'Piece', 'shortcode' => 'pcs'],
            ['id' => 2, 'name' => 'Meter', 'shortcode' => 'm'],
            ['id' => 3, 'name' => 'Square Feet', 'shortcode' => 'sq.ft'],
            ['id' => 4, 'name' => 'Yards', 'shortcode' => 'yrds'],
        ]);


    }
}
