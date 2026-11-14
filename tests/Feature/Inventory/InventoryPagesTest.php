<?php

use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Settings\Models\Branch;
use Database\Seeders\DefaultCatalogSeeder;

it('renders every inventory page', function () {
    $main = Branch::factory()->create();
    $mall = Branch::factory()->create();
    $admin = userWithRole('Admin', [$main, $mall]);
    $this->actingAs($admin);
    (new DefaultCatalogSeeder)->run();
    $v = makeVariant(branch: $main, stock: 10);

    $adjustment = app(StockAdjustmentService::class)->create($main, ['date' => now()->toDateString(), 'reason' => 'damage', 'mode' => 'change', 'items' => [['product_variant_id' => $v->id, 'quantity' => -1]]], $admin);
    $transfer = app(StockTransferService::class)->send($main, $mall, [['product_variant_id' => $v->id, 'quantity' => 2]], $admin);

    $pages = [
        route('categories.index') => 'Inventory/Catalog/Categories',
        route('brands.index') => 'Inventory/Catalog/Brands',
        route('attributes.index') => 'Inventory/Catalog/Attributes',
        route('units.index') => 'Inventory/Catalog/Units',
        route('tax-rates.index') => 'Inventory/Catalog/TaxRates',
        route('products.index') => 'Inventory/Products/Index',
        route('products.create') => 'Inventory/Products/Form',
        route('products.edit', $v->product_id) => 'Inventory/Products/Form',
        route('products.show', $v->product_id) => 'Inventory/Products/Show',
        route('products.import') => 'Inventory/Products/Import',
        route('stock.index') => 'Inventory/Stock/Index',
        route('stock.ledger') => 'Inventory/Stock/Ledger',
        route('stock-adjustments.index') => 'Inventory/Adjustments/Index',
        route('stock-adjustments.create') => 'Inventory/Adjustments/Create',
        route('stock-adjustments.show', $adjustment) => 'Inventory/Adjustments/Show',
        route('stock-transfers.index') => 'Inventory/Transfers/Index',
        route('stock-transfers.create') => 'Inventory/Transfers/Create',
        route('stock-transfers.show', $transfer) => 'Inventory/Transfers/Show',
        route('labels.index', ['variants' => [$v->id]]) => 'Inventory/Labels/Index',
    ];

    foreach ($pages as $url => $component) {
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component));
    }

    expect(StockAdjustment::count())->toBe(1)->and(StockTransfer::count())->toBe(1);
});

it('hides inventory admin pages from cashiers', function () {
    $branch = Branch::factory()->create();
    $this->actingAs(userWithRole('Cashier', [$branch]));

    $this->get(route('stock-adjustments.index'))->assertForbidden();
    $this->get(route('stock-transfers.index'))->assertForbidden();
    $this->get(route('labels.index'))->assertForbidden();
    $this->get(route('products.import'))->assertForbidden();
    $this->get(route('stock.index'))->assertOk();
});
