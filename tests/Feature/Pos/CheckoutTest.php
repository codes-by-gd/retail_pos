<?php

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\CustomerGroup;
use App\Modules\Inventory\Models\TaxRate;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Pos\Services\RegisterService;
use App\Modules\Sales\Models\Sale;
use App\Rules\Gstin;

beforeEach(fn () => $this->ctx = posSetup());

it('sells to a walk-in customer, deducts stock and numbers the invoice', function () {
    $shirt = garmentVariant($this->ctx->branch, 1799, 10);

    $res = checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 4000]], 3598)
        ->assertCreated()
        ->assertJsonPath('sale.change_given', 402);

    $sale = Sale::with('items', 'payments')->first();
    expect($sale->invoice_no)->toMatch('#^BR1/\d{4}/0{5,}1$#')
        ->and(strlen($sale->invoice_no))->toBeLessThanOrEqual(16)
        ->and($sale)->grand_total->toBe('3598.00')->taxable_total->toBe('3426.67')->cgst_total->toBe('85.67')->sgst_total->toBe('85.66')
        ->and($sale->items[0])->gst_rate->toBe('5.00')->unit_cost->toBe('1079.4000')
        ->and(app(StockService::class)->quantity($shirt->id, $this->ctx->branch->id))->toBe('8.000')
        ->and($sale->customer->is_walk_in)->toBeTrue()
        ->and($sale->payment_status)->toBe('paid');
});

it('rejects the bill when the screen total differs from the server total', function () {
    $shirt = garmentVariant($this->ctx->branch, 1799);

    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1799]], 1700)
        ->assertUnprocessable()->assertJsonValidationErrors('expected_total');
    expect(Sale::count())->toBe(0);
});

it('uses IGST for a customer in another state and 18% above ₹2,500 a piece', function () {
    $jeans = garmentVariant($this->ctx->branch, 2999);
    $gstin = '27AAKFK4321B1Z'.Gstin::checksum('27AAKFK4321B1Z');
    $boutique = Contact::factory()->create(['state_code' => '27', 'gstin' => $gstin]);

    checkout($this->ctx, [['variant_id' => $jeans->id, 'quantity' => 1]], [['method' => 'upi', 'amount' => 2999, 'reference' => 'UPI123']], 2999,
        ['customer_id' => $boutique->id])->assertCreated();

    expect(Sale::first())->is_inter_state->toBeTrue()->igst_total->toBe('457.47')->cgst_total->toBe('0.00')->customer_gstin->toBe($gstin)
        ->and(Sale::first()->items[0]->gst_rate)->toBe('18.00');
});

it('blocks discounts and price changes without permission', function () {
    $shirt = garmentVariant($this->ctx->branch, 1799);
    $this->ctx->user->roles->first()->revokePermissionTo('pos.discount');

    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1, 'discount_type' => 'percent', 'discount_value' => 10]], [['method' => 'cash', 'amount' => 1620]], 1619)
        ->assertJsonValidationErrors('items.0.discount_value');
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1, 'unit_price' => 1500]], [['method' => 'cash', 'amount' => 1500]], 1500)
        ->assertJsonValidationErrors('items.0.unit_price');
});

it('applies the customer group discount and wholesale price automatically', function () {
    $shirt = garmentVariant($this->ctx->branch, 1799, 10, ['wholesale_price' => 1500]);
    $this->ctx->user->roles->first()->revokePermissionTo('pos.discount');   // group discount needs no permission
    $gold = Contact::factory()->create(['customer_group_id' => CustomerGroup::create(['name' => 'Gold', 'price_type' => 'retail', 'discount_percent' => 5])->id]);
    $trader = Contact::factory()->create(['customer_group_id' => CustomerGroup::where('name', 'Wholesale')->value('id')]);

    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1709]], 1709,
        ['customer_id' => $gold->id, 'bill_discount_type' => 'percent', 'bill_discount_value' => 5])->assertCreated();
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1500]], 1500,
        ['customer_id' => $trader->id])->assertCreated();

    expect(Sale::where('customer_id', $trader->id)->first())->price_type->toBe('wholesale')
        ->and(Sale::where('customer_id', $trader->id)->first()->items[0]->unit_price)->toBe('1500.00');
});

it('does not allow a price above MRP', function () {
    $ctx = $this->ctx;
    $ctx->user->roles->first()->givePermissionTo('pos.price_override');
    $shirt = garmentVariant($ctx->branch, 1799, 5, ['mrp' => 1999]);

    checkout($ctx, [['variant_id' => $shirt->id, 'quantity' => 1, 'unit_price' => 2100]], [['method' => 'cash', 'amount' => 2100]], 2100)
        ->assertJsonValidationErrors('items.0.unit_price');
});

it('checks payments: short, overpaid card, on account rules', function () {
    $shirt = garmentVariant($this->ctx->branch, 1799);
    $item = [['variant_id' => $shirt->id, 'quantity' => 1]];

    checkout($this->ctx, $item, [['method' => 'cash', 'amount' => 1000]], 1799)->assertJsonValidationErrors('payments');
    checkout($this->ctx, $item, [['method' => 'card', 'amount' => 2000]], 1799)->assertJsonValidationErrors('payments');
    checkout($this->ctx, $item, [['method' => 'account', 'amount' => 1799]], 1799)->assertJsonValidationErrors('payments'); // walk-in

    $noCredit = Contact::factory()->create(['credit_limit' => 0]);
    checkout($this->ctx, $item, [['method' => 'account', 'amount' => 1799]], 1799, ['customer_id' => $noCredit->id])
        ->assertJsonValidationErrors('payments');

    expect(Sale::count())->toBe(0);
});

it('puts part of the bill on a customer account and posts the ledger', function () {
    $shirt = garmentVariant($this->ctx->branch, 1799);
    $anjali = Contact::factory()->create(['credit_limit' => 5000]);

    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]],
        [['method' => 'cash', 'amount' => 1000], ['method' => 'account', 'amount' => 799]], 1799, ['customer_id' => $anjali->id])->assertCreated();

    expect(Sale::first())->payment_status->toBe('partial')->account_amount->toBe('799.00')
        ->and($anjali->balance())->toBe('799.00')
        ->and($anjali->ledger()->pluck('type')->map->value->all())->toBe(['sale', 'payment_received']);
});

it('refuses to sell more than in stock unless negative stock is allowed', function () {
    $shirt = garmentVariant($this->ctx->branch, 1799, 1);

    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 3598]], 3598)
        ->assertUnprocessable();
    expect(Sale::count())->toBe(0);

    settings()->set('pos.allow_negative_stock', true);
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 3598]], 3598)->assertCreated();
});

it('sells fabric by the metre and rejects fractions of a piece', function () {
    $fabric = makeVariant(['selling_price' => 290], ['unit_id' => Unit::where('short_name', 'mtr')->value('id'),
        'tax_rate_id' => TaxRate::where('name', 'GST 5%')->value('id')], $this->ctx->branch, 50);
    $shirt = garmentVariant($this->ctx->branch);

    checkout($this->ctx, [['variant_id' => $fabric->id, 'quantity' => 1.25]], [['method' => 'cash', 'amount' => 363]], 363)->assertCreated();
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1.5]], [['method' => 'cash', 'amount' => 3000]], 2699)
        ->assertJsonValidationErrors('items.0.quantity');
});

it('needs an open register shift', function () {
    $shirt = garmentVariant($this->ctx->branch);
    app(RegisterService::class)->close($this->ctx->session, 1000, null, $this->ctx->user);

    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1799]], 1799)->assertStatus(409);
});

it('keeps invoice numbers sequential per branch', function () {
    $shirt = garmentVariant($this->ctx->branch, 500, 10);
    foreach (range(1, 3) as $i) {
        checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 500]], 500)->assertCreated();
    }

    expect(Sale::pluck('invoice_no')->map(fn ($n) => (int) substr($n, -5))->all())->toBe([1, 2, 3]);
});
