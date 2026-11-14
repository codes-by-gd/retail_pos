<?php

use App\Modules\Inventory\Enums\StockMovementType as T;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InsufficientStockException;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Settings\Models\Branch;

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->stock = app(StockService::class);
});

it('adds and removes stock and writes the ledger', function () {
    $v = makeVariant();

    $this->stock->move($v, $this->branch->id, 10, T::Opening, 50);
    $this->stock->move($v, $this->branch->id, -3, T::AdjustmentOut);

    expect($this->stock->quantity($v->id, $this->branch->id))->toBe('7.000')
        ->and(StockMovement::where('product_variant_id', $v->id)->pluck('balance_after')->all())->toBe(['10.000', '7.000']);
});

it('keeps a weighted average cost', function () {
    $v = makeVariant();

    $this->stock->move($v, $this->branch->id, 10, T::Purchase, 100);   // 10 @ 100
    $this->stock->move($v, $this->branch->id, 30, T::Purchase, 120);   // 30 @ 120 → (1000+3600)/40 = 115
    $this->stock->move($v, $this->branch->id, -5, T::Sale);            // out at average, average unchanged

    expect($this->stock->averageCost($v->id, $this->branch->id))->toBe('115.0000')
        ->and(StockMovement::latest('id')->first()->unit_cost)->toBe('115.0000');
});

it('resets average cost when stock was zero or negative', function () {
    $v = makeVariant();
    $this->stock->move($v, $this->branch->id, -2, T::Sale, allowNegative: true);
    $this->stock->move($v, $this->branch->id, 5, T::Purchase, 90);

    expect($this->stock->averageCost($v->id, $this->branch->id))->toBe('90.0000');
});

it('blocks selling more than available unless negative stock is allowed', function () {
    $v = makeVariant();
    $this->stock->move($v, $this->branch->id, 2, T::Opening, 10);

    expect(fn () => $this->stock->move($v, $this->branch->id, -5, T::Sale))
        ->toThrow(InsufficientStockException::class);
    expect($this->stock->quantity($v->id, $this->branch->id))->toBe('2.000');

    settings()->set('pos.allow_negative_stock', true);
    $this->stock->move($v, $this->branch->id, -5, T::Sale);
    expect($this->stock->quantity($v->id, $this->branch->id))->toBe('-3.000');
});

it('handles decimal quantities for loose items', function () {
    $v = makeVariant();
    $this->stock->move($v, $this->branch->id, '10.5', T::Opening, 80);
    $this->stock->move($v, $this->branch->id, '-0.25', T::Sale);

    expect($this->stock->quantity($v->id, $this->branch->id))->toBe('10.250');
});

it('ignores products that do not track stock', function () {
    $v = makeVariant(product: ['track_stock' => false]);

    expect($this->stock->move($v, $this->branch->id, -5, T::Sale))->toBeNull()
        ->and(StockLevel::count())->toBe(0);
});

it('keeps stock separate per branch', function () {
    $other = Branch::factory()->create();
    $v = makeVariant();
    $this->stock->move($v, $this->branch->id, 5, T::Opening, 10);
    $this->stock->move($v, $other->id, 2, T::Opening, 10);

    expect($this->stock->quantity($v->id, $this->branch->id))->toBe('5.000')
        ->and($this->stock->quantity($v->id, $other->id))->toBe('2.000');
});
