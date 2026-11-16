<?php

use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\ProfileController;
use App\Modules\Contacts\Http\Controllers\ContactController;
use App\Modules\Contacts\Http\Controllers\ContactImportController;
use App\Modules\Contacts\Http\Controllers\ContactPaymentController;
use App\Modules\Contacts\Http\Controllers\ContactSearchController;
use App\Modules\Contacts\Http\Controllers\CustomerGroupController;
use App\Modules\Expenses\Http\Controllers\ExpenseCategoryController;
use App\Modules\Expenses\Http\Controllers\ExpenseController;
use App\Modules\Inventory\Http\Controllers\AttributeController;
use App\Modules\Inventory\Http\Controllers\BrandController;
use App\Modules\Inventory\Http\Controllers\CategoryController;
use App\Modules\Inventory\Http\Controllers\LabelController;
use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\ProductImportController;
use App\Modules\Inventory\Http\Controllers\StockAdjustmentController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockTransferController;
use App\Modules\Inventory\Http\Controllers\TaxRateController;
use App\Modules\Inventory\Http\Controllers\UnitController;
use App\Modules\Inventory\Http\Controllers\VariantSearchController;
use App\Modules\Loyalty\Http\Controllers\LoyaltyController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Pos\Http\Controllers\CheckoutController;
use App\Modules\Pos\Http\Controllers\HeldCartController;
use App\Modules\Pos\Http\Controllers\PosController;
use App\Modules\Pos\Http\Controllers\RegisterController;
use App\Modules\Pos\Http\Controllers\RegisterSessionController;
use App\Modules\Pos\Http\Controllers\ReturnController;
use App\Modules\Purchases\Http\Controllers\PurchaseController;
use App\Modules\Purchases\Http\Controllers\PurchaseOrderController;
use App\Modules\Reports\Http\Controllers\ReportController;
use App\Modules\Sales\Http\Controllers\PrintController;
use App\Modules\Sales\Http\Controllers\QuotationController;
use App\Modules\Sales\Http\Controllers\SaleController;
use App\Modules\Settings\Http\Controllers\BackupController;
use App\Modules\Settings\Http\Controllers\BranchController;
use App\Modules\Settings\Http\Controllers\BusinessSettingsController;
use App\Modules\Settings\Http\Controllers\MailSettingsController;
use App\Modules\Users\Http\Controllers\ActivityController;
use App\Modules\Users\Http\Controllers\RoleController;
use App\Modules\Users\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/branch/switch', BranchSwitchController::class)->name('branch.switch');
    Route::get('/search', GlobalSearchController::class)->middleware('throttle:120,1')->name('search');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Inventory — catalog masters
    Route::middleware('can:catalog.manage')->prefix('catalog')->group(function () {
        Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('brands', BrandController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('attributes', AttributeController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('units', UnitController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('tax-rates', TaxRateController::class)->only(['index', 'store', 'update', 'destroy'])
            ->parameters(['tax-rates' => 'taxRate']);
    });

    // Inventory — products (static paths before {product})
    Route::get('/inventory/variants/search', VariantSearchController::class)->name('variants.search');
    Route::middleware('can:products.manage')->group(function () {
        Route::get('/products/import', [ProductImportController::class, 'create'])->name('products.import');
        Route::post('/products/import', [ProductImportController::class, 'store'])->name('products.import.store');
        Route::get('/products/import/template', [ProductImportController::class, 'template'])->name('products.import.template');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    });
    Route::middleware('can:products.view')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/export', [ProductImportController::class, 'export'])->name('products.export');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('/stock/ledger', [StockController::class, 'ledger'])->name('stock.ledger');
    });
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('can:products.delete')->name('products.destroy');

    // Inventory — stock documents
    Route::middleware('can:stock.adjust')->group(function () {
        Route::resource('stock-adjustments', StockAdjustmentController::class)->only(['index', 'create', 'store', 'show']);
    });
    Route::middleware('can:stock.transfer')->group(function () {
        Route::resource('stock-transfers', StockTransferController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('/stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive'])->name('stock-transfers.receive');
        Route::post('/stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->name('stock-transfers.cancel');
    });
    Route::get('/labels', [LabelController::class, 'index'])->middleware('can:labels.print')->name('labels.index');

    // POS billing
    Route::middleware('can:pos.access')->prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosController::class, 'terminal'])->name('terminal');
        Route::post('/register/open', [PosController::class, 'openRegister'])->name('register.open');
        Route::post('/register/leave', [PosController::class, 'leaveRegister'])->name('register.leave');
        Route::post('/unlock', [PosController::class, 'unlock'])->name('unlock');
        Route::post('/checkout', CheckoutController::class)->name('checkout');
        Route::get('/held', [HeldCartController::class, 'index'])->name('held.index');
        Route::post('/held', [HeldCartController::class, 'store'])->name('held.store');
        Route::delete('/held/{heldCart}', [HeldCartController::class, 'destroy'])->name('held.destroy');
        Route::get('/exchange-credit', [ReturnController::class, 'exchangeCredit'])->name('exchange-credit');
        Route::middleware('can:pos.returns')->group(function () {
            Route::get('/returns/lookup', [ReturnController::class, 'lookup'])->name('returns.lookup');
            Route::post('/returns', [ReturnController::class, 'store'])->name('returns.store');
        });
        Route::post('/cash-movements', [RegisterSessionController::class, 'cashMovement'])->middleware('can:pos.register.manage')->name('cash-movements.store');
    });
    Route::middleware('can:pos.register.manage')->group(function () {
        Route::get('/register-sessions', [RegisterSessionController::class, 'index'])->name('register-sessions.index');
        Route::get('/register-sessions/{registerSession}', [RegisterSessionController::class, 'show'])->name('register-sessions.show');
        Route::post('/register-sessions/{registerSession}/close', [RegisterSessionController::class, 'close'])->name('register-sessions.close');
    });

    // Sales (list/invoice; cancellation in Phase 4)
    Route::middleware('can:sales.view')->group(function () {
        Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
        Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
    });
    Route::post('/sales/{sale}/cancel', [SaleController::class, 'cancel'])->middleware('can:sales.cancel')->name('sales.cancel');

    // Quotations
    Route::middleware('can:quotations.manage')->group(function () {
        Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('/quotations/create', [QuotationController::class, 'create'])->name('quotations.create');
        Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
        Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
        Route::get('/quotations/{quotation}/edit', [QuotationController::class, 'edit'])->name('quotations.edit');
        Route::put('/quotations/{quotation}', [QuotationController::class, 'update'])->name('quotations.update');
        Route::delete('/quotations/{quotation}', [QuotationController::class, 'destroy'])->name('quotations.destroy');
        Route::post('/quotations/{quotation}/status', [QuotationController::class, 'status'])->name('quotations.status');
        Route::post('/quotations/{quotation}/convert', [QuotationController::class, 'convert'])->middleware('can:pos.access')->name('quotations.convert');
        Route::get('/quotations/{quotation}/print', [QuotationController::class, 'print'])->name('quotations.print');
    });

    // Purchasing
    Route::middleware('can:purchases.manage')->group(function () {
        Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
        Route::get('/purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])->name('purchase-orders.edit');
        Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
        Route::post('/purchase-orders/{purchaseOrder}/status', [PurchaseOrderController::class, 'status'])->name('purchase-orders.status');
        Route::get('/purchases/create', [PurchaseController::class, 'create'])->name('purchases.create');
        Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
        Route::post('/purchases/{purchase}/returns', [PurchaseController::class, 'storeReturn'])->name('purchases.returns.store');
    });
    Route::middleware('can:purchases.view')->group(function () {
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
        Route::get('/purchase-orders/{purchaseOrder}/print', [PurchaseOrderController::class, 'print'])->name('purchase-orders.print');
        Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');
        Route::get('/purchase-returns/{purchaseReturn}/print', [PurchaseController::class, 'printReturn'])->name('purchase-returns.print');
    });

    // Payments to / from contacts
    Route::middleware('can:payments.manage')->group(function () {
        Route::get('/payments', [ContactPaymentController::class, 'index'])->name('payments.index');
        Route::post('/contacts/{contact}/payments', [ContactPaymentController::class, 'store'])->name('contacts.payments.store');
        Route::get('/payments/{contactPayment}/print', [ContactPaymentController::class, 'print'])->name('payments.print');
    });

    // Reports — each report checks its own permission (reports.sales / .inventory / .financial)
    Route::middleware('can:reports.any')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/{report}', [ReportController::class, 'show'])->name('show');
        Route::get('/{report}/export', [ReportController::class, 'export'])->name('export');
    });

    Route::get('/sales/{sale}/print', [PrintController::class, 'sale'])->middleware('can:sales.view')->name('sales.print');
    Route::get('/sale-returns/{saleReturn}/print', [PrintController::class, 'saleReturn'])->middleware('can:sales.view')->name('sale-returns.print');

    // Contacts — customers and suppliers share controllers; `kind` comes from the route.
    foreach (['customer' => 'customers', 'supplier' => 'suppliers'] as $kind => $plural) {
        Route::prefix($plural)->name("{$plural}.")->group(function () use ($kind, $plural) {
            Route::middleware("can:{$plural}.manage")->group(function () use ($kind) {
                Route::get('/create', [ContactController::class, 'create'])->name('create')->defaults('kind', $kind);
                Route::post('/', [ContactController::class, 'store'])->name('store')->defaults('kind', $kind);
                Route::get('/import', [ContactImportController::class, 'create'])->name('import')->defaults('kind', $kind);
                Route::post('/import', [ContactImportController::class, 'store'])->name('import.store')->defaults('kind', $kind);
                Route::get('/import/template', [ContactImportController::class, 'template'])->name('import.template')->defaults('kind', $kind);
                Route::get('/{contact}/edit', [ContactController::class, 'edit'])->name('edit')->defaults('kind', $kind);
                Route::put('/{contact}', [ContactController::class, 'update'])->name('update')->defaults('kind', $kind);
                Route::delete('/{contact}', [ContactController::class, 'destroy'])->name('destroy')->defaults('kind', $kind);
            });
            Route::middleware("can:{$plural}.view")->group(function () use ($kind) {
                Route::get('/', [ContactController::class, 'index'])->name('index')->defaults('kind', $kind);
                Route::get('/export', [ContactImportController::class, 'export'])->name('export')->defaults('kind', $kind);
                Route::get('/{contact}', [ContactController::class, 'show'])->name('show')->defaults('kind', $kind);
            });
        });
    }
    Route::resource('customer-groups', CustomerGroupController::class)->only(['index', 'store', 'update', 'destroy'])
        ->middleware('can:customers.manage');
    Route::get('/contacts/search', [ContactSearchController::class, 'search'])->name('contacts.search');
    Route::post('/contacts/quick', [ContactSearchController::class, 'quickStore'])->name('contacts.quick-store');

    // Users & roles
    Route::middleware('can:users.view')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
    });
    Route::middleware('can:users.manage')->group(function () {
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])->middleware('throttle:10,1')->name('users.password-reset');
    });
    Route::middleware('can:roles.manage')->group(function () {
        Route::resource('roles', RoleController::class)->except('show');
    });

    // Expenses (static paths before {expense})
    Route::middleware('can:expenses.manage')->group(function () {
        Route::resource('expenses/categories', ExpenseCategoryController::class)->only(['index', 'store', 'update', 'destroy'])
            ->names('expense-categories')->parameters(['categories' => 'expenseCategory']);
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
        Route::get('/expenses/{expense}/attachment', [ExpenseController::class, 'attachment'])->name('expenses.attachment');
    });

    // Notifications — every user, own notifications only
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/summary', [NotificationController::class, 'summary'])->name('summary');
        Route::post('/read-all', [NotificationController::class, 'readAll'])->name('read-all');
        Route::delete('/read', [NotificationController::class, 'clearRead'])->name('clear-read');
        Route::put('/preferences', [NotificationController::class, 'preferences'])->name('preferences');
        Route::post('/test', [NotificationController::class, 'test'])->middleware('throttle:10,1')->name('test');
        Route::get('/{id}/open', [NotificationController::class, 'open'])->name('open');
        Route::post('/{id}/toggle', [NotificationController::class, 'toggle'])->name('toggle');
    });

    Route::post('/customers/{contact}/loyalty', [LoyaltyController::class, 'adjust'])->middleware('can:loyalty.adjust')->name('customers.loyalty.adjust');

    Route::get('/activity', [ActivityController::class, 'index'])->middleware('can:activity.view')->name('activity.index');

    // Settings
    Route::middleware('can:settings.manage')->prefix('settings')->name('settings.')->group(function () {
        Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
        Route::put('/backups/settings', [BackupController::class, 'settings'])->name('backups.settings');
        Route::get('/backups/{name}', [BackupController::class, 'download'])->name('backups.download')->where('name', '[A-Za-z0-9.\-]+');
        Route::delete('/backups/{name}', [BackupController::class, 'destroy'])->name('backups.destroy')->where('name', '[A-Za-z0-9.\-]+');
        Route::get('/business', [BusinessSettingsController::class, 'edit'])->name('business.edit');
        Route::put('/business', [BusinessSettingsController::class, 'update'])->name('business.update');
        Route::get('/email', [MailSettingsController::class, 'edit'])->name('mail.edit');
        Route::put('/email', [MailSettingsController::class, 'update'])->name('mail.update');
        Route::post('/email/test', [MailSettingsController::class, 'test'])->middleware('throttle:10,1')->name('mail.test');
        Route::post('/business/logo', [BusinessSettingsController::class, 'uploadLogo'])->name('business.logo');
        Route::delete('/business/logo', [BusinessSettingsController::class, 'removeLogo'])->name('business.logo.destroy');
        Route::resource('branches', BranchController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('registers', RegisterController::class)->only(['index', 'store', 'update', 'destroy']);
    });
});

require __DIR__.'/auth.php';
