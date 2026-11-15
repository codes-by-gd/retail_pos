<?php

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\CustomerGroup;
use App\Modules\Pos\Models\HeldCart;
use App\Modules\Sales\Models\Quotation;

beforeEach(function () {
    $this->ctx = posSetup('Manager');
    $this->shirt = garmentVariant($this->ctx->branch, 1799, 20, ['wholesale_price' => 1500]);
});

function quotePayload(array $overrides = []): array
{
    return ['customer_name' => 'Mr Shah (wedding order)', 'date' => now()->toDateString(), 'valid_until' => now()->addDays(10)->toDateString(),
        'items' => [], 'bill_discount_type' => 'percent', 'bill_discount_value' => 0, ...$overrides];
}

it('creates a quotation priced like the POS and numbers it', function () {
    $gold = Contact::factory()->create(['customer_group_id' => CustomerGroup::where('name', 'Wholesale')->value('id')]);

    posAs($this->ctx)->post(route('quotations.store'), quotePayload([
        'customer_id' => $gold->id,
        'items' => [['variant_id' => $this->shirt->id, 'quantity' => 10, 'discount_type' => 'percent', 'discount_value' => 5]],
        'bill_discount_type' => 'amount', 'bill_discount_value' => 250,
    ]))->assertSessionHasNoErrors();

    $q = Quotation::with('items')->first();
    expect($q->reference_no)->toStartWith('QTN/BR1/')
        ->and($q->items[0]->unit_price)->toBe('1500.00')               // wholesale price
        ->and($q->grand_total)->toBe('14000.00');                       // 15000 − 5% − 250
});

it('converts to a held POS bill that checks out at the quoted total', function () {
    posAs($this->ctx)->post(route('quotations.store'), quotePayload([
        'items' => [['variant_id' => $this->shirt->id, 'quantity' => 3, 'discount_type' => 'percent', 'discount_value' => 10]],
    ]));
    $q = Quotation::first();

    posAs($this->ctx)->post(route('quotations.convert', $q))->assertRedirect(route('pos.terminal', ['recall' => HeldCart::first()->id]));
    $cart = HeldCart::first();
    expect($q->fresh()->status)->toBe('converted')
        ->and($cart->payload['items'][0])->toMatchArray(['variant_id' => $this->shirt->id, 'quantity' => 3, 'discount_type' => 'percent', 'discount_value' => 10])
        ->and($cart->payload['customer']['is_walk_in'])->toBeTrue();

    $item = $cart->payload['items'][0];
    checkout($this->ctx, [['variant_id' => $item['variant_id'], 'quantity' => $item['quantity'], 'discount_type' => 'percent', 'discount_value' => 10]],
        [['method' => 'cash', 'amount' => (float) $q->grand_total]], (float) $q->grand_total, ['held_cart_id' => $cart->id])->assertCreated();
    expect(HeldCart::count())->toBe(0);

    posAs($this->ctx)->post(route('quotations.convert', $q))->assertSessionHas('error');   // only once
});

it('edits drafts only and tracks status', function () {
    posAs($this->ctx)->post(route('quotations.store'), quotePayload(['items' => [['variant_id' => $this->shirt->id, 'quantity' => 1]]]));
    $q = Quotation::first();

    posAs($this->ctx)->put(route('quotations.update', $q), quotePayload(['items' => [['variant_id' => $this->shirt->id, 'quantity' => 2]]]))->assertSessionHasNoErrors();
    expect($q->fresh()->grand_total)->toBe('3598.00');

    posAs($this->ctx)->post(route('quotations.status', $q), ['status' => 'rejected']);
    posAs($this->ctx)->put(route('quotations.update', $q), quotePayload(['items' => [['variant_id' => $this->shirt->id, 'quantity' => 5]]]))->assertSessionHas('error');
    expect($q->fresh()->grand_total)->toBe('3598.00');
});
