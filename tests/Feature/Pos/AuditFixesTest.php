<?php

use App\Modules\Contacts\Models\Contact;
use App\Modules\Sales\Models\Sale;
use App\Modules\Settings\Models\Branch;

beforeEach(fn () => $this->ctx = posSetup('Admin'));

it('will not delete a branch that has sales or stock history', function () {
    Branch::factory()->create();   // so it is not the only branch
    $shirt = garmentVariant($this->ctx->branch, 999);
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 999]], 999)->assertCreated();

    posAs($this->ctx)->delete(route('settings.branches.destroy', $this->ctx->branch))->assertSessionHas('error');
    expect(Branch::find($this->ctx->branch->id))->not->toBeNull();

    $empty = Branch::factory()->create();
    posAs($this->ctx)->delete(route('settings.branches.destroy', $empty))->assertSessionHas('success');
});

it("shows today's sales on the dashboard", function () {
    $shirt = garmentVariant($this->ctx->branch, 1000);
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1000]], 1000)->assertCreated();
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 2]], [['method' => 'card', 'amount' => 2000]], 2000)->assertCreated();

    posAs($this->ctx)->get(route('dashboard'))->assertInertia(fn ($p) => $p
        ->where('today.bills', 2)->where('today.total', 3000)->where('today.average', 1500));
});

it('links sale movements in the stock ledger to the invoice', function () {
    $shirt = garmentVariant($this->ctx->branch, 1000);
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1000]], 1000)->assertCreated();
    $sale = Sale::first();

    posAs($this->ctx)->get(route('stock.ledger', ['variant_id' => $shirt->id]))->assertInertia(fn ($p) => $p
        ->where('movements.data.0.reference', $sale->invoice_no)
        ->where('movements.data.0.reference_url', route('sales.show', $sale)));
});

it('never bills a walk-in customer as inter-state, even if a state was saved on it', function () {
    $walkIn = Contact::where('is_walk_in', true)->first();
    $walkIn->forceFill(['state_code' => '27'])->save();   // legacy data
    $shirt = garmentVariant($this->ctx->branch, 1000);

    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1000]], 1000)->assertCreated();
    expect(Sale::first())->is_inter_state->toBeFalse()->place_of_supply->toBe('24');

    posAs($this->ctx)->put(route('customers.update', $walkIn), ['type' => 'customer', 'name' => 'Walk-in Customer', 'state_code' => '27', 'is_active' => true]);
    expect($walkIn->fresh()->state_code)->toBeNull();
});
