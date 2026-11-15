<?php

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactPayment;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Pos\Services\RegisterService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;

beforeEach(function () {
    $this->ctx = posSetup('Manager');
    $this->shirt = garmentVariant($this->ctx->branch, 1000, 20);
});

function sellTo(object $ctx, $variant, int $qty, array $payments, float $total, ?int $customerId = null): Sale
{
    checkout($ctx, [['variant_id' => $variant->id, 'quantity' => $qty]], $payments, $total, ['customer_id' => $customerId])->assertCreated();

    return Sale::latest('id')->first();
}

it('tracks dues on account and settles them oldest first with a payment', function () {
    $c = Contact::factory()->create(['credit_limit' => 10000]);
    $old = sellTo($this->ctx, $this->shirt, 1, [['method' => 'account', 'amount' => 1000]], 1000, $c->id);
    $new = sellTo($this->ctx, $this->shirt, 2, [['method' => 'cash', 'amount' => 500], ['method' => 'account', 'amount' => 1500]], 2000, $c->id);

    expect($old)->due_amount->toBe('1000.00')->payment_status->toBe('due')
        ->and($new)->due_amount->toBe('1500.00')->payment_status->toBe('partial');

    posAs($this->ctx)->post(route('contacts.payments.store', $c), ['direction' => 'in', 'amount' => 1800, 'method' => 'upi', 'reference' => 'UTR1', 'date' => now()->toDateString()])
        ->assertSessionHas('success');

    $payment = ContactPayment::first();
    expect($payment)->reference_no->toStartWith('RCPT/BR1/')->unallocated->toBe('0.00')
        ->and($old->fresh())->due_amount->toBe('0.00')->payment_status->toBe('paid')
        ->and($new->fresh())->due_amount->toBe('700.00')->payment_status->toBe('partial')
        ->and($c->balance())->toBe('700.00');
});

it('keeps an overpayment as advance and uses store credit on the next bill', function () {
    $c = Contact::factory()->create(['credit_limit' => 0]);
    posAs($this->ctx)->post(route('contacts.payments.store', $c), ['direction' => 'in', 'amount' => 600, 'method' => 'cash', 'date' => now()->toDateString()]);
    expect(ContactPayment::first()->unallocated)->toBe('600.00')->and($c->balance())->toBe('-600.00');

    $sale = sellTo($this->ctx, $this->shirt, 1, [['method' => 'account', 'amount' => 600], ['method' => 'cash', 'amount' => 400]], 1000, $c->id);
    expect($sale)->due_amount->toBe('0.00')->payment_status->toBe('paid')->and($c->balance())->toBe('0.00');
});

it('counts cash payments taken into the drawer', function () {
    $c = Contact::factory()->create();
    posAs($this->ctx)->post(route('contacts.payments.store', $c), ['direction' => 'in', 'amount' => 750, 'method' => 'cash', 'date' => now()->toDateString(),
        'register_session_id' => $this->ctx->session->id]);
    $card = Contact::factory()->create();
    posAs($this->ctx)->post(route('contacts.payments.store', $card), ['direction' => 'in', 'amount' => 300, 'method' => 'card', 'date' => now()->toDateString(),
        'register_session_id' => $this->ctx->session->id]);   // non-cash never touches the drawer

    expect(app(RegisterService::class)->summary($this->ctx->session))->payments_in->toBe('750.00')->expected_cash->toBe('1750.00');
});

it('cancels a bill in the open shift: stock back, account reversed, exchange credit released', function () {
    $c = Contact::factory()->create(['credit_limit' => 5000]);
    $first = sellTo($this->ctx, $this->shirt, 1, [['method' => 'cash', 'amount' => 1000]], 1000);
    $credit = posAs($this->ctx)->postJson(route('pos.returns.store'), ['sale_id' => $first->id, 'items' => [['sale_item_id' => $first->items[0]->id, 'quantity' => 1]],
        'refund_method' => 'exchange'])->json('return');

    $sale = sellTo($this->ctx, $this->shirt, 2, [['method' => 'exchange', 'amount' => 1000, 'sale_return_id' => $credit['id']],
        ['method' => 'cash', 'amount' => 500], ['method' => 'account', 'amount' => 500]], 2000, $c->id);
    $stockBefore = app(StockService::class)->quantity($this->shirt->id, $this->ctx->branch->id);
    expect($c->balance())->toBe('500.00');

    posAs($this->ctx)->post(route('sales.cancel', $sale), ['reason' => 'Wrong customer'])->assertSessionHas('success');

    expect($sale->fresh())->status->toBe('cancelled')->cancel_reason->toBe('Wrong customer')->due_amount->toBe('0.00')
        ->and(app(StockService::class)->quantity($this->shirt->id, $this->ctx->branch->id))->toBe(number_format((float) $stockBefore + 2, 3, '.', ''))
        ->and($c->balance())->toBe('0.00')
        ->and(SaleReturn::find($credit['id'])->used_in_sale_id)->toBeNull()
        ->and(app(RegisterService::class)->summary($this->ctx->session)['sales_count'])->toBe(1);
});

it('refuses to cancel after returns, after the shift closed, or without permission', function () {
    $a = sellTo($this->ctx, $this->shirt, 2, [['method' => 'cash', 'amount' => 2000]], 2000);
    posAs($this->ctx)->postJson(route('pos.returns.store'), ['sale_id' => $a->id, 'items' => [['sale_item_id' => $a->items[0]->id, 'quantity' => 1]], 'refund_method' => 'cash']);
    posAs($this->ctx)->post(route('sales.cancel', $a), ['reason' => 'x'])->assertSessionHas('error');

    $b = sellTo($this->ctx, $this->shirt, 1, [['method' => 'cash', 'amount' => 1000]], 1000);
    $cashier = userWithRole('Cashier', [$this->ctx->branch]);
    posAs($this->ctx, $cashier)->post(route('sales.cancel', $b), ['reason' => 'x'])->assertForbidden();

    app(RegisterService::class)->close($this->ctx->session, 3000, null, $this->ctx->user);
    posAs($this->ctx)->post(route('sales.cancel', $b), ['reason' => 'x'])->assertSessionHas('error');
    expect($b->fresh()->status)->toBe('completed');
});

it('only takes payments from customers and pays suppliers', function () {
    $supplier = Contact::factory()->supplier()->create();
    posAs($this->ctx)->post(route('contacts.payments.store', $supplier), ['direction' => 'in', 'amount' => 10, 'method' => 'cash', 'date' => now()->toDateString()])
        ->assertSessionHas('error');
    posAs($this->ctx)->post(route('contacts.payments.store', $supplier), ['direction' => 'out', 'amount' => 10, 'method' => 'bank', 'date' => now()->toDateString()])
        ->assertSessionHas('success');
    expect(ContactPayment::first()->reference_no)->toStartWith('PAY/');
});
