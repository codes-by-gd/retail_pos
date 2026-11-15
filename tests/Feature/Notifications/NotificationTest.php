<?php

use App\Modules\Notifications\Notifications\AlertNotification;
use App\Modules\Notifications\NotificationTypes;
use App\Modules\Pos\Services\RegisterService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Settings\Models\Branch;

beforeEach(function () {
    $this->ctx = posSetup();   // cashier with an open shift
    $this->manager = userWithRole('Manager', [$this->ctx->branch]);
    $this->keeper = userWithRole('Stock Keeper', [$this->ctx->branch]);
    $other = Branch::factory()->create(['code' => 'BR2', 'state_code' => '24']);
    $this->otherManager = userWithRole('Manager', [$other]);
});

function alertsOf($user, string $type)
{
    return $user->fresh()->notifications->filter(fn ($n) => $n->data['type'] === $type)->values();
}

it('alerts stock people of this branch once when an item falls to its reorder level', function () {
    $tee = garmentVariant($this->ctx->branch, 549, 4, ['reorder_level' => 2]);
    checkout($this->ctx, [['variant_id' => $tee->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 549]], 549)->assertCreated();   // 3 left
    expect(alertsOf($this->keeper, NotificationTypes::LOW_STOCK))->toHaveCount(0);

    checkout($this->ctx, [['variant_id' => $tee->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 549]], 549)->assertCreated();   // 2 left → alert
    checkout($this->ctx, [['variant_id' => $tee->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 549]], 549)->assertCreated();   // 1 left, still low → no new alert

    $alerts = alertsOf($this->keeper, NotificationTypes::LOW_STOCK);
    expect($alerts)->toHaveCount(1)->and($alerts[0]->data['title'])->toStartWith('Low stock')
        ->and(alertsOf($this->manager, NotificationTypes::LOW_STOCK))->toHaveCount(1)
        ->and(alertsOf($this->otherManager, NotificationTypes::LOW_STOCK))->toHaveCount(0)   // not their branch
        ->and($this->ctx->user->fresh()->notifications)->toHaveCount(0);                     // cashier can't manage stock

    // after it's read, running out raises a new one
    $alerts[0]->markAsRead();
    checkout($this->ctx, [['variant_id' => $tee->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 549]], 549)->assertCreated();   // 0 → not a new crossing
    expect(alertsOf($this->keeper, NotificationTypes::LOW_STOCK))->toHaveCount(1);
});

it('tells managers about cancellations, big discounts and cash differences — not the person who did it', function () {
    settings()->set(['alerts.discount_percent' => 20, 'alerts.cash_difference' => 50]);
    $shirt = garmentVariant($this->ctx->branch, 1000, 10);

    // 25% bill discount → alert; 10% → none
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 750]], 750, ['bill_discount_type' => 'percent', 'bill_discount_value' => 25])->assertCreated();
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 900]], 900, ['bill_discount_type' => 'percent', 'bill_discount_value' => 10])->assertCreated();
    expect(alertsOf($this->manager, NotificationTypes::BIG_DISCOUNT))->toHaveCount(1)
        ->and(alertsOf($this->manager, NotificationTypes::BIG_DISCOUNT)[0]->data['title'])->toContain('25%');

    // manager cancels → the other managers hear about it, not the canceller
    $second = userWithRole('Manager', [$this->ctx->branch]);
    posAs($this->ctx, $this->manager)->post(route('sales.cancel', Sale::latest('id')->first()), ['reason' => 'Test'])->assertSessionHas('success');
    expect(alertsOf($second, NotificationTypes::SALE_CANCELLED))->toHaveCount(1)
        ->and(alertsOf($this->manager, NotificationTypes::SALE_CANCELLED))->toHaveCount(0);

    // close ₹100 short
    $expected = (float) app(RegisterService::class)->summary($this->ctx->session)['expected_cash'];
    app(RegisterService::class)->close($this->ctx->session, $expected - 100, 'Miscount', $this->ctx->user);
    $cash = alertsOf($this->manager, NotificationTypes::CASH_DIFFERENCE);
    expect($cash)->toHaveCount(1)->and($cash[0]->data)->level->toBe('danger')->title->toContain('short');
});

it('respects preferences and lets users manage only their own notifications', function () {
    $this->actingAs($this->manager)->put(route('notifications.preferences'), ['off' => [NotificationTypes::SALE_CANCELLED]])->assertSessionHas('success');
    $sale = (function () {
        $tee = garmentVariant($this->ctx->branch, 549, 10);
        checkout($this->ctx, [['variant_id' => $tee->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 549]], 549)->assertCreated();

        return Sale::first();
    })();
    $second = userWithRole('Manager', [$this->ctx->branch]);
    posAs($this->ctx, $second)->post(route('sales.cancel', $sale), ['reason' => 'Test']);
    expect(alertsOf($this->manager, NotificationTypes::SALE_CANCELLED))->toHaveCount(0);

    // bell endpoints
    $this->manager->notify(new AlertNotification(NotificationTypes::DAILY_SUMMARY, 'Hello', 'Body', route('dashboard')));
    $n = $this->manager->fresh()->notifications->first();
    $this->actingAs($this->manager)->getJson(route('notifications.summary'))->assertOk()->assertJsonPath('unread', 1)->assertJsonPath('latest.0.title', 'Hello');
    $this->actingAs($second)->get(route('notifications.open', $n->id))->assertNotFound();
    $this->actingAs($this->manager)->get(route('notifications.open', $n->id))->assertRedirect(route('dashboard'));
    expect($n->fresh()->read_at)->not->toBeNull();
    $this->actingAs($this->manager)->get(route('notifications.index'))->assertOk()->assertInertia(fn ($p) => $p->where('unreadCount', 0)->has('types'));
});

it('sends the daily summary and due alerts from the scheduled command', function () {
    $tee = garmentVariant($this->ctx->branch, 549, 10);
    checkout($this->ctx, [['variant_id' => $tee->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 1098]], 1098)->assertCreated();

    $this->artisan('pos:daily-alerts')->assertSuccessful();
    $summary = alertsOf($this->manager, NotificationTypes::DAILY_SUMMARY);
    expect($summary)->toHaveCount(1)->and($summary[0]->data['body'])->toContain('1 bills')->toContain('1,098.00')
        ->and(alertsOf($this->otherManager, NotificationTypes::DAILY_SUMMARY))->toHaveCount(0);

    // running it twice the same day doesn't duplicate unread alerts
    $this->artisan('pos:daily-alerts')->assertSuccessful();
    expect(alertsOf($this->manager, NotificationTypes::DAILY_SUMMARY))->toHaveCount(1);
});

it('shares the unread count with every page', function () {
    $this->manager->notify(new AlertNotification(NotificationTypes::DAILY_SUMMARY, 'Hi', 'There'));
    $this->actingAs($this->manager)->get(route('dashboard'))->assertInertia(fn ($p) => $p->where('notifications.unread', 1));
});
