<?php

use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Settings\Models\Branch;
use App\Support\CurrentBranch;

beforeEach(function () {
    $this->main = Branch::factory()->create(['code' => 'BR1', 'name' => 'Main']);
    $this->mall = Branch::factory()->create(['code' => 'BR2', 'name' => 'Mall']);
    $this->keeper = userWithRole('Stock Keeper', [$this->main, $this->mall]);
    $this->stock = app(StockService::class);
    $this->v = makeVariant(branch: $this->main, stock: 10, cost: 50);
});

function inBranch($test, Branch $branch)
{
    return $test->actingAs($test->keeper)->withSession([CurrentBranch::SESSION_KEY => $branch->id]);
}

it('adjusts stock up and down with a reference number', function () {
    inBranch($this, $this->main)->post(route('stock-adjustments.store'), [
        'date' => now()->toDateString(), 'reason' => 'damage', 'mode' => 'change',
        'items' => [['product_variant_id' => $this->v->id, 'quantity' => -3]],
    ])->assertSessionHasNoErrors();

    $adj = StockAdjustment::with('items')->first();
    expect($adj->reference_no)->toStartWith('ADJ/BR1/')
        ->and($adj->items->first()->quantity)->toBe('-3.000')
        ->and($this->stock->quantity($this->v->id, $this->main->id))->toBe('7.000');
});

it('posts the difference in stock-count mode', function () {
    inBranch($this, $this->main)->post(route('stock-adjustments.store'), [
        'date' => now()->toDateString(), 'reason' => 'count', 'mode' => 'count',
        'items' => [['product_variant_id' => $this->v->id, 'counted' => 13]],
    ])->assertSessionHasNoErrors();

    expect(StockAdjustment::first()->items->first()->quantity)->toBe('3.000')
        ->and($this->stock->quantity($this->v->id, $this->main->id))->toBe('13.000');
});

it('refuses an adjustment that would make stock negative', function () {
    inBranch($this, $this->main)->post(route('stock-adjustments.store'), [
        'date' => now()->toDateString(), 'reason' => 'damage', 'mode' => 'change',
        'items' => [['product_variant_id' => $this->v->id, 'quantity' => -11]],
    ])->assertSessionHas('error');

    expect(StockAdjustment::count())->toBe(0)
        ->and($this->stock->quantity($this->v->id, $this->main->id))->toBe('10.000');
});

it('sends and receives a transfer between branches', function () {
    inBranch($this, $this->main)->post(route('stock-transfers.store'), [
        'to_branch_id' => $this->mall->id, 'items' => [['product_variant_id' => $this->v->id, 'quantity' => 4]],
    ])->assertSessionHasNoErrors();
    $transfer = StockTransfer::first();

    expect($transfer->status)->toBe('in_transit')
        ->and($this->stock->quantity($this->v->id, $this->main->id))->toBe('6.000')
        ->and($this->stock->quantity($this->v->id, $this->mall->id))->toBe('0');

    // Cannot receive while working in the sending branch
    inBranch($this, $this->main)->post(route('stock-transfers.receive', $transfer))->assertForbidden();

    inBranch($this, $this->mall)->post(route('stock-transfers.receive', $transfer))->assertSessionHas('success');
    expect($transfer->fresh()->status)->toBe('received')
        ->and($this->stock->quantity($this->v->id, $this->mall->id))->toBe('4.000')
        ->and($this->stock->averageCost($this->v->id, $this->mall->id))->toBe('50.0000');

    // Cannot receive twice
    inBranch($this, $this->mall)->post(route('stock-transfers.receive', $transfer))->assertSessionHas('error');
    expect($this->stock->quantity($this->v->id, $this->mall->id))->toBe('4.000');
});

it('returns stock to the source when a transfer is cancelled', function () {
    inBranch($this, $this->main)->post(route('stock-transfers.store'), [
        'to_branch_id' => $this->mall->id, 'items' => [['product_variant_id' => $this->v->id, 'quantity' => 4]],
    ]);
    $transfer = StockTransfer::first();

    inBranch($this, $this->main)->post(route('stock-transfers.cancel', $transfer))->assertSessionHas('success');

    expect($transfer->fresh()->status)->toBe('cancelled')
        ->and($this->stock->quantity($this->v->id, $this->main->id))->toBe('10.000');
});

it('cannot transfer more than is in stock or to the same branch', function () {
    inBranch($this, $this->main)->post(route('stock-transfers.store'), [
        'to_branch_id' => $this->mall->id, 'items' => [['product_variant_id' => $this->v->id, 'quantity' => 50]],
    ])->assertSessionHas('error');

    inBranch($this, $this->main)->post(route('stock-transfers.store'), [
        'to_branch_id' => $this->main->id, 'items' => [['product_variant_id' => $this->v->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('to_branch_id');

    expect(StockTransfer::count())->toBe(0);
});

it('shows the stock overview and ledger for the current branch', function () {
    inBranch($this, $this->main)->get(route('stock.index'))
        ->assertInertia(fn ($p) => $p->where('rows.data.0.quantity', 10)->where('totals.value', 500));

    inBranch($this, $this->main)->get(route('stock.ledger', ['variant_id' => $this->v->id]))
        ->assertInertia(fn ($p) => $p->has('movements.data', 1)->where('movements.data.0.type', 'opening'));

    inBranch($this, $this->mall)->get(route('stock.ledger'))
        ->assertInertia(fn ($p) => $p->has('movements.data', 0));
});

it('counts low and out-of-stock items on the dashboard', function () {
    makeVariant(['reorder_level' => 20], branch: $this->main, stock: 5); // low
    makeVariant(['reorder_level' => 0]);                                  // out (0 <= 0)
    // $this->v: 10 in stock, reorder 0 → fine

    inBranch($this, $this->main)->get(route('dashboard'))->assertInertia(fn ($p) => $p->loadDeferredProps(fn ($reload) => $reload->where('details.lowStock.count', 2)));
    inBranch($this, $this->main)->get(route('stock.index', ['level' => 'low']))->assertInertia(fn ($p) => $p->has('rows.data', 2));
});
