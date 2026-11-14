<?php

use App\Modules\Pos\Http\Controllers\PosController;
use App\Modules\Pos\Models\HeldCart;
use App\Modules\Pos\Models\Register;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Pos\Services\RegisterService;
use App\Modules\Sales\Models\Sale;
use App\Support\CurrentBranch;

beforeEach(fn () => $this->ctx = posSetup());

it('shows the open-register screen until a shift is chosen, then the terminal', function () {
    $this->actingAs($this->ctx->user)->withSession([CurrentBranch::SESSION_KEY => $this->ctx->branch->id])
        ->get(route('pos.terminal'))->assertInertia(fn ($p) => $p->component('Pos/OpenRegister')->where('registers.0.open_session.opened_by', $this->ctx->user->name));

    $this->post(route('pos.register.open'), ['register_id' => $this->ctx->register->id])->assertRedirect(route('pos.terminal'));
    expect(session(PosController::SESSION_KEY))->toBe($this->ctx->session->id);   // joined the open shift

    $this->get(route('pos.terminal'))->assertInertia(fn ($p) => $p->component('Pos/Terminal')->where('session.register', 'Counter 1'));
});

it('allows only one open shift per register', function () {
    $again = app(RegisterService::class)->open($this->ctx->register, $this->ctx->user, 500);

    expect($again->id)->toBe($this->ctx->session->id)->and(RegisterSession::count())->toBe(1);
});

it('reconciles cash: float + cash sales − change + cash in − cash out', function () {
    $shirt = garmentVariant($this->ctx->branch, 1799);
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 2000]], 1799)->assertCreated();
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'card', 'amount' => 1799]], 1799)->assertCreated();
    posAs($this->ctx)->postJson(route('pos.cash-movements.store'), ['type' => 'in', 'amount' => 500, 'reason' => 'Change float'])->assertCreated();
    posAs($this->ctx)->postJson(route('pos.cash-movements.store'), ['type' => 'out', 'amount' => 300, 'reason' => 'Tea & snacks'])->assertCreated();

    $summary = app(RegisterService::class)->summary($this->ctx->session);
    expect($summary)->expected_cash->toBe('2999.00')->sales_count->toBe(2)->gross_sales->toBe('3598.00')
        ->and(collect($summary['payments'])->firstWhere('method', 'card')['total'])->toBe('1799.00');

    posAs($this->ctx)->post(route('register-sessions.close', $this->ctx->session), ['counted_cash' => 2990, 'closing_note' => 'Short ₹9'])
        ->assertRedirect(route('register-sessions.show', $this->ctx->session));

    expect($this->ctx->session->fresh())->status->toBe('closed')->expected_cash->toBe('2999.00')->difference->toBe('-9.00')
        ->and(session(PosController::SESSION_KEY))->toBeNull();
});

it('cannot take out more cash than is in the drawer', function () {
    posAs($this->ctx)->postJson(route('pos.cash-movements.store'), ['type' => 'out', 'amount' => 5000, 'reason' => 'Bank'])->assertUnprocessable();
});

it('holds, lists and deletes parked bills', function () {
    posAs($this->ctx)->postJson(route('pos.held.store'), ['label' => 'Priya — trial room', 'payload' => ['items' => [['variant_id' => 1, 'quantity' => 1]], 'total' => 1799]])
        ->assertCreated()->assertJsonPath('count', 1);
    posAs($this->ctx)->getJson(route('pos.held.index'))->assertJsonPath('data.0.label', 'Priya — trial room')->assertJsonPath('data.0.items', 1);
    posAs($this->ctx)->deleteJson(route('pos.held.destroy', HeldCart::first()))->assertJsonPath('count', 0);
});

it('switches cashier with a PIN', function () {
    $other = userWithRole('Cashier', [$this->ctx->branch]);
    $other->update(['pin' => '4321']);

    posAs($this->ctx)->postJson(route('pos.unlock'), ['user_id' => $other->id, 'pin' => '0000'])->assertJsonValidationErrors('pin');
    posAs($this->ctx)->postJson(route('pos.unlock'), ['user_id' => $other->id, 'pin' => '4321'])->assertOk()->assertJsonPath('user.id', $other->id);

    expect(auth()->id())->toBe($other->id)->and(session(PosController::SESSION_KEY))->toBe($this->ctx->session->id);
});

it('prints receipts and invoices and renders POS pages', function () {
    $shirt = garmentVariant($this->ctx->branch, 1799);
    checkout($this->ctx, [['variant_id' => $shirt->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1799]], 1799)->assertCreated();
    $sale = Sale::first();
    $admin = userWithRole('Admin', [$this->ctx->branch]);

    foreach (['80mm', '58mm', 'a4'] as $format) {
        posAs($this->ctx, $admin)->get(route('sales.print', [$sale, 'format' => $format]))->assertOk()->assertSee($sale->invoice_no);
    }
    posAs($this->ctx, $admin)->get(route('sales.print', [$sale, 'format' => 'a4']))->assertSee('Rupees One Thousand Seven Hundred Ninety Nine Only');

    posAs($this->ctx, $admin)->get(route('sales.index'))->assertInertia(fn ($p) => $p->component('Sales/Index')->where('summary.total', 1799));
    posAs($this->ctx, $admin)->get(route('sales.show', $sale))->assertInertia(fn ($p) => $p->component('Sales/Show'));
    posAs($this->ctx, $admin)->get(route('register-sessions.index'))->assertInertia(fn ($p) => $p->component('Pos/Sessions/Index'));
    posAs($this->ctx, $admin)->get(route('register-sessions.show', $this->ctx->session))->assertInertia(fn ($p) => $p->component('Pos/Sessions/Show'));
    posAs($this->ctx, $admin)->get(route('settings.registers.index'))->assertInertia(fn ($p) => $p->component('Settings/Registers'));
    posAs($this->ctx, $admin)->get(route('pos.terminal'))->assertInertia(fn ($p) => $p->component('Pos/Terminal'));
});

it('creates a default register with each new branch', function () {
    $admin = userWithRole('Admin');
    $this->actingAs($admin)->post(route('settings.branches.store'), ['name' => 'Outlet', 'code' => 'OUT', 'state_code' => '24', 'is_active' => true]);

    expect(Register::whereHas('branch', fn ($q) => $q->where('code', 'OUT'))->pluck('name')->all())->toBe(['Counter 1']);
});
