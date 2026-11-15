<?php

use App\Modules\Contacts\Models\Contact;
use App\Modules\Loyalty\Models\LoyaltyTransaction;
use App\Modules\Loyalty\Services\LoyaltyService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->ctx = posSetup('Manager');   // manager: discounts, cancel, customers.manage
    $this->shirt = garmentVariant($this->ctx->branch, 1000, 20);
    $this->priya = Contact::factory()->create(['type' => Contact::CUSTOMER, 'state_code' => '24', 'credit_limit' => 0]);
    $this->loyalty = app(LoyaltyService::class);
});

function loyaltySale(object $ctx, int $qty, array $payments, float $total, ?int $customerId)
{
    return checkout($ctx, [['variant_id' => test()->shirt->id, 'quantity' => $qty]], $payments, $total, ['customer_id' => $customerId]);
}

it('earns 1 point per ₹100 for named customers only', function () {
    loyaltySale($this->ctx, 2, [['method' => 'cash', 'amount' => 2000]], 2000, $this->priya->id)->assertCreated();
    loyaltySale($this->ctx, 1, [['method' => 'cash', 'amount' => 1000]], 1000, null)->assertCreated();

    $sale = Sale::where('customer_id', $this->priya->id)->first();
    $tx = LoyaltyTransaction::first();
    expect($sale->points_earned)->toBe(20)->and($this->loyalty->balance($this->priya))->toBe(20)
        ->and(LoyaltyTransaction::count())->toBe(1)
        ->and($tx->expires_at->toDateString())->toBe(now()->addMonthsNoOverflow(12)->toDateString());

    posAs($this->ctx)->get(route('sales.print', $sale))->assertOk()->assertSee('Points balance')->assertSee('+20');
});

it('pays with points: limits, oldest points first, no points on the redeemed part', function () {
    Carbon::setTestNow(now()->subMonths(2));
    $this->loyalty->adjust($this->priya, 150, 'Welcome bonus', $this->ctx->user);
    Carbon::setTestNow();
    $this->loyalty->adjust($this->priya, 100, 'Diwali bonus', $this->ctx->user);

    // too few, too many for the bill, more than the balance
    loyaltySale($this->ctx, 1, [['method' => 'points', 'amount' => 50], ['method' => 'cash', 'amount' => 950]], 1000, $this->priya->id)
        ->assertUnprocessable()->assertJsonValidationErrors('payments.0.amount');
    loyaltySale($this->ctx, 1, [['method' => 'points', 'amount' => 600], ['method' => 'cash', 'amount' => 400]], 1000, $this->priya->id)
        ->assertUnprocessable();
    // walk-in can't use points
    loyaltySale($this->ctx, 1, [['method' => 'points', 'amount' => 200], ['method' => 'cash', 'amount' => 800]], 1000, null)
        ->assertUnprocessable();

    loyaltySale($this->ctx, 1, [['method' => 'points', 'amount' => 200], ['method' => 'cash', 'amount' => 800]], 1000, $this->priya->id)->assertCreated();
    $sale = Sale::latest('id')->first();
    $batches = LoyaltyTransaction::where('type', LoyaltyTransaction::ADJUST)->orderBy('id')->get();

    expect($sale)->points_redeemed->toBe(200)->points_earned->toBe(8)   // earned on the ₹800 paid in cash
        ->and($sale->payments->firstWhere('method', 'points')->reference)->toBe('200 points')
        ->and($batches[0]->remaining)->toBe(0)->and($batches[1]->remaining)->toBe(50)   // older batch used first
        ->and($this->loyalty->balance($this->priya))->toBe(250 - 200 + 8)
        // points count like money in the account: nothing owed
        ->and($this->priya->fresh()->balance())->toBe('0.00');
});

it('takes points back on returns and undoes everything on cancel', function () {
    loyaltySale($this->ctx, 4, [['method' => 'cash', 'amount' => 4000]], 4000, $this->priya->id)->assertCreated();   // +40
    $sale = Sale::with('items')->latest('id')->first();
    posAs($this->ctx)->postJson(route('pos.returns.store'), ['sale_id' => $sale->id, 'refund_method' => 'cash',
        'items' => [['sale_item_id' => $sale->items[0]->id, 'quantity' => 1, 'restock' => true]]])->assertCreated();
    expect($this->loyalty->balance($this->priya))->toBe(30);
    posAs($this->ctx)->postJson(route('pos.returns.store'), ['sale_id' => $sale->id, 'refund_method' => 'cash',
        'items' => [['sale_item_id' => $sale->items[0]->id, 'quantity' => 3, 'restock' => true]]])->assertCreated();
    expect($this->loyalty->balance($this->priya))->toBe(0);

    // cancel a bill partly paid with points
    $this->loyalty->adjust($this->priya, 300, 'Bonus', $this->ctx->user);
    loyaltySale($this->ctx, 1, [['method' => 'points', 'amount' => 300], ['method' => 'cash', 'amount' => 700]], 1000, $this->priya->id)->assertCreated();
    expect($this->loyalty->balance($this->priya))->toBe(7);
    posAs($this->ctx)->post(route('sales.cancel', Sale::latest('id')->first()), ['reason' => 'Wrong size'])->assertSessionHas('success');
    expect($this->loyalty->balance($this->priya))->toBe(300)
        ->and($this->priya->fresh()->balance())->toBe('0.00');
});

it('expires what is left of old batches', function () {
    Carbon::setTestNow(now()->subMonths(13));
    $this->loyalty->adjust($this->priya, 120, 'Old', $this->ctx->user);
    Carbon::setTestNow();
    $this->loyalty->adjust($this->priya, 30, 'New', $this->ctx->user);

    $this->artisan('pos:expire-points')->expectsOutputToContain('120 loyalty point(s) expired')->assertSuccessful();
    expect($this->loyalty->balance($this->priya))->toBe(30)
        ->and(LoyaltyTransaction::where('type', LoyaltyTransaction::EXPIRE)->sum('points'))->toEqual(-120);
});

it('adjusts points with permission and shows them on the customer page and report', function () {
    posAs($this->ctx)->post(route('customers.loyalty.adjust', $this->priya), ['points' => 50, 'note' => 'Goodwill'])->assertSessionHas('success');
    posAs($this->ctx)->post(route('customers.loyalty.adjust', $this->priya), ['points' => -80, 'note' => 'Too many'])->assertSessionHas('error');
    $this->actingAs(userWithRole('Cashier', [$this->ctx->branch]))->post(route('customers.loyalty.adjust', $this->priya), ['points' => 10, 'note' => 'x'])->assertForbidden();

    posAs($this->ctx)->get(route('customers.show', $this->priya))->assertInertia(fn ($p) => $p->where('loyalty.balance', 50)->has('loyalty.history', 1));
    $row = posAs($this->ctx)->get(route('reports.show', 'loyalty'))->viewData('page')['props']['sections'][0]['rows'][0];
    expect($row)->balance->toBe(50)->value->toEqual(50)->adjusted->toBe(50);
});

it('can be switched off', function () {
    settings()->set(['loyalty.enabled' => false]);
    loyaltySale($this->ctx, 1, [['method' => 'cash', 'amount' => 1000]], 1000, $this->priya->id)->assertCreated();
    expect(LoyaltyTransaction::count())->toBe(0);
    loyaltySale($this->ctx, 1, [['method' => 'points', 'amount' => 100], ['method' => 'cash', 'amount' => 900]], 1000, $this->priya->id)->assertUnprocessable();
});
