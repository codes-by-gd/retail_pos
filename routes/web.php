<?php

use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Modules\Contacts\Http\Controllers\ContactController;
use App\Modules\Contacts\Http\Controllers\ContactImportController;
use App\Modules\Contacts\Http\Controllers\ContactSearchController;
use App\Modules\Contacts\Http\Controllers\CustomerGroupController;
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
use App\Modules\Pos\Http\Controllers\CheckoutController;
use App\Modules\Pos\Http\Controllers\HeldCartController;
use App\Modules\Pos\Http\Controllers\PosController;
use App\Modules\Pos\Http\Controllers\RegisterController;
use App\Modules\Pos\Http\Controllers\RegisterSessionController;
use App\Modules\Pos\Http\Controllers\ReturnController;
use App\Modules\Sales\Http\Controllers\PrintController;
use App\Modules\Sales\Http\Controllers\SaleController;
use App\Modules\Settings\Http\Controllers\BranchController;
use App\Modules\Settings\Http\Controllers\BusinessSettingsController;
use App\Modules\Users\Http\Controllers\RoleController;
use App\Modules\Users\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/branch/switch', BranchSwitchController::class)->name('branch.switch');

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
    });
    Route::middleware('can:roles.manage')->group(function () {
        Route::resource('roles', RoleController::class)->except('show');
    });

    // Settings
    Route::middleware('can:settings.manage')->prefix('settings')->name('settings.')->group(function () {
        Route::get('/business', [BusinessSettingsController::class, 'edit'])->name('business.edit');
        Route::put('/business', [BusinessSettingsController::class, 'update'])->name('business.update');
        Route::resource('branches', BranchController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('registers', RegisterController::class)->only(['index', 'store', 'update', 'destroy']);
    });
});

require __DIR__.'/auth.php';
