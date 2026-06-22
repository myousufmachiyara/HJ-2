<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\{
    DashboardController,
    SubHeadOfAccController,
    COAController,
    SaleInvoiceController,
    ProductionController,
    PurchaseInvoiceController,
    PurchaseReturnController,
    ProductController,
    UserController,
    RoleController,
    AttributeController,
    ProductCategoryController,
    ProductSubcategoryController,
    ProductionReceivingController,
    VoucherController,
    InventoryReportController,
    PurchaseReportController,
    ProductionReportController,
    SalesReportController,
    AccountsReportController,
    SummaryReportController,
    SaleReturnController,
    PermissionController,
    LocationController,
    StockTransferController,
    ProductionReturnController,
    ProductionWastageController,
    PosController,
    ShopifyStoreController
};

Auth::routes();

Route::middleware(['auth'])->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────────
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::view('/unauthorized', 'unauthorized')->name('unauthorized');

    // ── User helpers ──────────────────────────────────────────────────
    Route::put('/users/{id}/change-password', [UserController::class, 'changePassword'])->name('users.changePassword');
    Route::put('/users/{id}/toggle-active',   [UserController::class, 'toggleActive'])->name('users.toggleActive');

    // ── Product helpers ───────────────────────────────────────────────
    Route::get('/products/details',                     [ProductController::class, 'details'])->name('products.receiving');
    Route::get('/products/barcode-selection',           [ProductController::class, 'barcodeSelection'])->name('products.barcode.selection');
    Route::post('/products/generate-multiple-barcodes', [ProductController::class, 'generateMultipleBarcodes'])->name('products.generateBarcodes');
    Route::get('/get-product-by-code/{barcode}',        [ProductController::class, 'getByBarcode'])->name('product.byBarcode');
    Route::get('/product/{product}/variations',         [ProductController::class, 'getVariations'])->name('product.variations');
    Route::get('/product/{product}/variations2',        [ProductController::class, 'getVariations2'])->name('product.variations2');
    Route::get('/product/{product}/productions',        [ProductionController::class, 'getProductProductions'])->name('product.productions');
    Route::get('/get-subcategories/{category_id}', [ProductCategoryController::class, 'getSubcategories'])->name('products.getSubcategories');

    Route::get('/products/bulk-upload/template', [ProductController::class, 'bulkUploadTemplate'])->name('products.bulk-upload.template')->middleware('check.permission:products.create');
    Route::get('/products/bulk-export',          [ProductController::class, 'bulkExport'])->name('products.bulk-export')->middleware('check.permission:products.create');
    Route::post('/products/bulk-import',         [ProductController::class, 'bulkImport'])->name('products.bulk-import')->middleware('check.permission:products.create');

    // ── Purchase helpers ──────────────────────────────────────────────
    Route::get('/product/{product}/invoices', [PurchaseInvoiceController::class, 'getProductInvoices'])->name('product.invoices');

    // ── Production helpers ────────────────────────────────────────────
    Route::get('/production-summary/{id}',  [ProductionController::class, 'summary'])->name('production.summary');
    Route::get('/production-gatepass/{id}', [ProductionController::class, 'printGatepass'])->name('production.gatepass');
    
    // ── Sale Invoice payment helpers ──────────────────────────────────
    Route::post('/sale_invoices/{id}/payments',               [SaleInvoiceController::class, 'addPayment'])->name('sale_invoices.payments.store');
    Route::put('/sale_invoices/{id}/payments/{paymentId}',    [SaleInvoiceController::class, 'updatePayment'])->name('sale_invoices.payments.update');
    Route::delete('/sale_invoices/{id}/payments/{paymentId}', [SaleInvoiceController::class, 'deletePayment'])->name('sale_invoices.payments.destroy');

    // ── Vouchers (single tabbed page) ────────────────────────────────
    Route::get('vouchers', [VoucherController::class, 'index'])->middleware('check.permission:vouchers.index')->name('vouchers.all');

    // ── POS helper routes (permission-guarded) ────────────────────────
    // These are AJAX/action endpoints used from inside the POS screen.
    // They share pos_system.create (checkout/hold) and pos_system.index (recall/z-report/receipt)
    Route::prefix('pos')->name('pos.')->middleware('check.permission:pos_system.index')->group(function () {
        Route::post('/checkout',    [PosController::class, 'checkout'])->name('checkout')->middleware('check.permission:pos_system.create');
        Route::post('/hold',        [PosController::class, 'holdOrder'])->name('hold')->middleware('check.permission:pos_system.create');
        Route::get('/recall/{id}',  [PosController::class, 'recallOrder'])->name('recall');
        Route::delete('/held/{id}', [PosController::class, 'deleteHeldOrder'])->name('held.destroy')->middleware('check.permission:pos_system.delete');
        Route::get('/z-report',     [PosController::class, 'zReport'])->name('zreport');
        Route::get('/receipt/{id}', [PosController::class, 'receipt'])->name('receipt')->middleware('check.permission:pos_system.print');
    });

    // ── Common Modules ────────────────────────────────────────────────
    $modules = [
        // User Management
        'roles'       => ['controller' => RoleController::class,       'permission' => 'user_roles'],
        'permissions' => ['controller' => PermissionController::class,  'permission' => 'role_permissions'],
        'users'       => ['controller' => UserController::class,        'permission' => 'users'],

        // Accounts
        'coa'  => ['controller' => COAController::class,          'permission' => 'coa'],
        'shoa' => ['controller' => SubHeadOfAccController::class, 'permission' => 'shoa'],

        // Products
        'products'              => ['controller' => ProductController::class,         'permission' => 'products'],
        'product_categories'    => ['controller' => ProductCategoryController::class, 'permission' => 'product_categories'],
        'product_subcategories' => ['controller' => ProductSubcategoryController::class, 'permission' => 'product_subcategories'],
        'attributes'            => ['controller' => AttributeController::class,       'permission' => 'attributes'],

        // Stock Management
        'locations'      => ['controller' => LocationController::class,     'permission' => 'locations'],
        'stock_transfer' => ['controller' => StockTransferController::class, 'permission' => 'stock_transfer'],

        // Purchases
        'purchase_invoices' => ['controller' => PurchaseInvoiceController::class, 'permission' => 'purchase_invoices'],
        'purchase_return'   => ['controller' => PurchaseReturnController::class,  'permission' => 'purchase_return'],

        // Sales
        'sale_invoices' => ['controller' => SaleInvoiceController::class, 'permission' => 'sale_invoices'],
        'sale_return'   => ['controller' => SaleReturnController::class,  'permission' => 'sale_return'],

        // Vouchers
        'vouchers' => ['controller' => VoucherController::class, 'permission' => 'vouchers'],

        // Production
        'production'           => ['controller' => ProductionController::class,          'permission' => 'production'],
        'production_receiving' => ['controller' => ProductionReceivingController::class, 'permission' => 'production_receiving'],
        'production_return'    => ['controller' => ProductionReturnController::class,    'permission' => 'production_return'],
        'production_wastage'   => ['controller' => ProductionWastageController::class, 'permission' => 'production_wastage'],
        
        // POS System — treated exactly like other modules
        'pos_system' => ['controller' => PosController::class, 'permission' => 'pos_system'],
    ];

    foreach ($modules as $uri => $config) {
        $controller = $config['controller'];
        $permission = $config['permission'];
        $param      = $uri === 'roles' ? '{role}' : '{id}';

        // ── Vouchers: special prefix routing ─────────────────────────
        if ($uri === 'vouchers') {
            Route::prefix("$uri/{type}")->group(function () use ($controller, $permission) {
                Route::get('/',           [$controller, 'index'])->middleware("check.permission:$permission.index")->name('vouchers.index');
                Route::get('/create',     [$controller, 'create'])->middleware("check.permission:$permission.create")->name('vouchers.create');
                Route::post('/',          [$controller, 'store'])->middleware("check.permission:$permission.create")->name('vouchers.store');
                Route::get('/{id}',       [$controller, 'show'])->middleware("check.permission:$permission.index")->name('vouchers.show');
                Route::get('/{id}/edit',  [$controller, 'edit'])->middleware("check.permission:$permission.edit")->name('vouchers.edit');
                Route::put('/{id}',       [$controller, 'update'])->middleware("check.permission:$permission.edit")->name('vouchers.update');
                Route::delete('/{id}',    [$controller, 'destroy'])->middleware("check.permission:$permission.delete")->name('vouchers.destroy');
                Route::get('/{id}/print', [$controller, 'print'])->middleware("check.permission:$permission.print")->name('vouchers.print');
            });
            continue;
        }

        // ── POS System: custom route mapping ─────────────────────────
        // POS doesn't follow the standard create/edit/show/update pattern.
        // index  → pos_system/          → opens the POS terminal screen
        // store  → not applicable       → checkout is handled via AJAX prefix routes above
        // print  → pos_system/{id}/print → receipt PDF
        // delete → not applicable
        if ($uri === 'pos_system') {
            Route::get('pos_system', [$controller, 'index'])
                ->middleware("check.permission:$permission.index")
                ->name('pos_system.index');

            Route::get('pos_system/{id}/print', [$controller, 'receipt'])
                ->middleware("check.permission:$permission.print")
                ->name('pos_system.print');

            continue;
        }

        // ── Standard CRUD modules ─────────────────────────────────────
        Route::get("$uri",              [$controller, 'index'])  ->middleware("check.permission:$permission.index") ->name("$uri.index");
        Route::get("$uri/create",       [$controller, 'create']) ->middleware("check.permission:$permission.create")->name("$uri.create");
        Route::post("$uri",             [$controller, 'store'])  ->middleware("check.permission:$permission.create")->name("$uri.store");
        Route::get("$uri/$param",       [$controller, 'show'])   ->middleware("check.permission:$permission.index") ->name("$uri.show");
        Route::get("$uri/$param/edit",  [$controller, 'edit'])   ->middleware("check.permission:$permission.edit")  ->name("$uri.edit");
        Route::put("$uri/$param",       [$controller, 'update']) ->middleware("check.permission:$permission.edit")  ->name("$uri.update");
        Route::delete("$uri/$param",    [$controller, 'destroy'])->middleware("check.permission:$permission.delete")->name("$uri.destroy");
        Route::get("$uri/$param/print", [$controller, 'print'])  ->middleware("check.permission:$permission.print") ->name("$uri.print");
    }

    // ── Reports ───────────────────────────────────────────────────────
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('inventory',  [InventoryReportController::class,  'inventoryReports'])->name('inventory');
        Route::get('purchase',   [PurchaseReportController::class,   'purchaseReports'])->name('purchase');
        Route::get('production', [ProductionReportController::class, 'productionReports'])->name('production');
        Route::get('sale',       [SalesReportController::class,      'saleReports'])->name('sale');
        Route::get('accounts',   [AccountsReportController::class,   'accounts'])->name('accounts');
    });

    // ── Shopify ────────────────────────────────────────────────────────────────
    Route::prefix('shopify')->name('shopify.')->group(function () {

        Route::get('settings', [ShopifyStoreController::class, 'index'])
            ->name('settings');

        // Step 1: submit form → redirects to Shopify
        Route::post('store', [ShopifyStoreController::class, 'store'])
            ->name('store');

        // Step 2: Shopify redirects back here
        Route::get('oauth/callback', [ShopifyStoreController::class, 'oauthCallback'])
            ->name('oauth.callback');

        Route::post('store/{id}/sync', [ShopifyStoreController::class, 'manualSync'])
            ->name('store.sync');

        Route::delete('store/{id}', [ShopifyStoreController::class, 'destroy'])
            ->name('store.delete');

        Route::post('import', [ShopifyStoreController::class, 'import'])
            ->name('import');
    });
    
    // routes/web.php — outside auth middleware
    Route::get('/shopify/callback', function (\Illuminate\Http\Request $request) {
        $shop = $request->query('shop');
        $code = $request->query('code');

        $response = \Illuminate\Support\Facades\Http::post(
            "https://{$shop}/admin/oauth/access_token", [
            'client_id'     => config('services.shopify.client_id'),   // from .env
            'client_secret' => config('services.shopify.client_secret'), // from .env
            'code'          => $code,
        ]);

        dd($response->json()); // Remove this line after getting token
    });

});