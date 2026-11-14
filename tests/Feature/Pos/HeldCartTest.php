<?php

use App\Modules\Pos\Models\HeldCart;

beforeEach(fn () => $this->ctx = posSetup());

it('keeps the whole bill when holding: customer, discount, note, exchange credits', function () {
    $payload = [
        'items' => [['variant_id' => 5, 'quantity' => 2, 'unit_price' => null, 'discount_type' => 'percent', 'discount_value' => 10]],
        'customer' => ['id' => 7, 'name' => 'Priya Patel', 'phone' => '9876543210', 'is_walk_in' => false, 'discount_percent' => 5],
        'billDiscount' => ['type' => 'percent', 'value' => 5],
        'note' => 'Trial room 2',
        'exchangeCredits' => [['id' => 3, 'credit_note_no' => 'CNBR1/2627/00001', 'total' => 549]],
        'total' => 1709,
    ];

    posAs($this->ctx)->postJson(route('pos.held.store'), ['label' => 'Priya', 'payload' => $payload])->assertCreated();

    // toEqual: MySQL's JSON column re-orders object keys, which is harmless.
    expect(HeldCart::first()->payload)->toEqual($payload);
    posAs($this->ctx)->getJson(route('pos.held.index'))
        ->assertJsonPath('data.0.payload.customer.name', 'Priya Patel')
        ->assertJsonPath('data.0.total', 1709);
});
