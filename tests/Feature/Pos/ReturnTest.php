<?php

use App\Modules\Contacts\Models\Contact;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Pos\Services\RegisterService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;

beforeEach(function () {
    $this->ctx = posSetup();
    $this->shirt = garmentVariant($this->ctx->branch, 1799, 10);
    $this->tee = garmentVariant($this->ctx->branch, 549, 10);
});

function sell(object $ctx, array $items, float $total, ?int $customerId = null): Sale
{
    checkout($ctx, $items, [['method' => 'cash', 'amount' => $total]], $total, ['customer_id' => $customerId])->assertCreated();

    return Sale::latest('id')->first();
}

function returnItems(object $ctx, Sale $sale, array $items, string $method = 'cash')
{
    return posAs($ctx)->postJson(route('pos.returns.store'), ['sale_id' => $sale->id, 'items' => $items, 'refund_method' => $method, 'reason' => 'Size issue']);
}

it('returns part of a line, restocks it and refunds cash from the drawer', function () {
    $sale = sell($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 3]], 1647);
    $item = $sale->items[0];

    returnItems($this->ctx, $sale, [['sale_item_id' => $item->id, 'quantity' => 1, 'restock' => true]])
        ->assertCreated()->assertJsonPath('return.total', 549);

    $cn = SaleReturn::first();
    expect($cn->credit_note_no)->toStartWith('CNBR1/')->and(strlen($cn->credit_note_no))->toBeLessThanOrEqual(16)
        ->and($item->fresh()->returned_quantity)->toBe('1.000')
        ->and(app(StockService::class)->quantity($this->tee->id, $this->ctx->branch->id))->toBe('8.000')
        ->and(app(RegisterService::class)->summary($this->ctx->session)['expected_cash'])->toBe('2098.00'); // 1000 + 1647 − 549
});

it('refunds exactly the invoice when everything is returned in pieces', function () {
    $sale = sell($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 3, 'discount_type' => 'amount', 'discount_value' => 100]], 1547);
    $item = $sale->items[0];

    foreach ([1, 1, 1] as $q) {
        returnItems($this->ctx, $sale, [['sale_item_id' => $item->id, 'quantity' => $q]])->assertCreated();
    }

    expect((float) SaleReturn::sum('taxable_total'))->toBe((float) $item->taxable_value)
        ->and((float) SaleReturn::sum('total'))->toBe(1547.0);

    returnItems($this->ctx, $sale, [['sale_item_id' => $item->id, 'quantity' => 1]])->assertJsonValidationErrors('items.0.quantity');
});

it('does not restock damaged pieces', function () {
    $sale = sell($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 1]], 549);
    returnItems($this->ctx, $sale, [['sale_item_id' => $sale->items[0]->id, 'quantity' => 1, 'restock' => false]])->assertCreated();

    expect(app(StockService::class)->quantity($this->tee->id, $this->ctx->branch->id))->toBe('9.000');
});

it('exchanges a shirt for a different size, paying the difference', function () {
    $sale = sell($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 1]], 549);
    $credit = returnItems($this->ctx, $sale, [['sale_item_id' => $sale->items[0]->id, 'quantity' => 1]], 'exchange')->json('return');

    // New bill for the shirt: ₹549 exchange credit + ₹1,250 cash
    checkout($this->ctx, [['variant_id' => $this->shirt->id, 'quantity' => 1]], [
        ['method' => 'exchange', 'amount' => 549, 'sale_return_id' => $credit['id']],
        ['method' => 'cash', 'amount' => 1250],
    ], 1799)->assertCreated();

    expect(SaleReturn::first()->used_in_sale_id)->toBe(Sale::latest('id')->value('id'));

    // The same credit cannot be used twice
    checkout($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 1]], [['method' => 'exchange', 'amount' => 549, 'sale_return_id' => $credit['id']]], 549)
        ->assertJsonValidationErrors('payments.0.sale_return_id');
});

it('pays back the difference in cash when the exchange credit is larger', function () {
    $sale = sell($this->ctx, [['variant_id' => $this->shirt->id, 'quantity' => 1]], 1799);
    $credit = returnItems($this->ctx, $sale, [['sale_item_id' => $sale->items[0]->id, 'quantity' => 1]], 'exchange')->json('return');

    checkout($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 1]], [['method' => 'exchange', 'amount' => 1799, 'sale_return_id' => $credit['id']]], 549)
        ->assertCreated()->assertJsonPath('sale.change_given', 1250);

    // drawer: 1000 + 1799 (first sale) − 1250 change
    expect(app(RegisterService::class)->summary($this->ctx->session)['expected_cash'])->toBe('1549.00');
});

it('keeps the customer ledger balanced through sale, return and store credit', function () {
    $priya = Contact::factory()->create();
    $sale = sell($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 2]], 1098, $priya->id);
    expect($priya->balance())->toBe('0.00');

    returnItems($this->ctx, $sale, [['sale_item_id' => $sale->items[0]->id, 'quantity' => 1]], 'account')->assertCreated();
    expect($priya->balance())->toBe('-549.00');   // we owe her: store credit

    // she uses the store credit on her account for a new bill
    checkout($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 1]], [['method' => 'account', 'amount' => 549]], 549, ['customer_id' => $priya->id])
        ->assertCreated();
    expect($priya->balance())->toBe('0.00');

    $sale2 = sell($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 1]], 549, $priya->id);
    returnItems($this->ctx, $sale2, [['sale_item_id' => $sale2->items[0]->id, 'quantity' => 1]], 'cash')->assertCreated();
    expect($priya->balance())->toBe('0.00');
});

it('does not give store credit to walk-in customers and needs permission', function () {
    $sale = sell($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 1]], 549);
    returnItems($this->ctx, $sale, [['sale_item_id' => $sale->items[0]->id, 'quantity' => 1]], 'account')->assertJsonValidationErrors('refund_method');

    // Cashier role has no pos.returns by default
    $this->ctx->user->roles->first()->revokePermissionTo('pos.returns');
    returnItems($this->ctx, $sale, [['sale_item_id' => $sale->items[0]->id, 'quantity' => 1]])->assertForbidden();
});

it('looks up an invoice for returns', function () {
    $sale = sell($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 2]], 1098);

    posAs($this->ctx)->getJson(route('pos.returns.lookup', ['invoice_no' => strtolower($sale->invoice_no)]))
        ->assertOk()->assertJsonPath('sale.items.0.returnable', 2)->assertJsonPath('sale.items.0.unit_amount', 549);
    posAs($this->ctx)->getJson(route('pos.returns.lookup', ['invoice_no' => 'NOPE']))->assertNotFound();
});
