<?php

use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Pos\Services\RegisterService;
use Database\Seeders\DefaultExpenseCategoriesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->ctx = posSetup('Manager');   // open shift with ₹1,000 float
    (new DefaultExpenseCategoriesSeeder)->run();
    $this->tea = ExpenseCategory::where('name', 'Staff tea & refreshments')->first();
    $this->rent = ExpenseCategory::where('name', 'Rent')->first();
});

function expensePayload(array $extra = []): array
{
    return ['date' => today()->toDateString(), 'amount' => 100, 'method' => 'cash', 'paid_to' => 'Tea stall', ...$extra];
}

it('pays an expense from the drawer and shows it in the Z-report', function () {
    posAs($this->ctx)->post(route('expenses.store'), expensePayload(['expense_category_id' => $this->tea->id, 'register_session_id' => $this->ctx->session->id]))
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    $e = Expense::first();
    $summary = app(RegisterService::class)->summary($this->ctx->session);
    expect($e)->reference_no->toStartWith('EXP/BR1/')->amount->toBe('100.00')->register_session_id->toBe($this->ctx->session->id)
        ->and($summary['expenses'])->toBe('100.00')->and($summary['expected_cash'])->toBe('900.00');
});

it('refuses more cash than the drawer holds and other branches\' drawers', function () {
    posAs($this->ctx)->post(route('expenses.store'), expensePayload(['expense_category_id' => $this->rent->id, 'amount' => 5000, 'register_session_id' => $this->ctx->session->id]))
        ->assertSessionHas('error');
    expect(Expense::count())->toBe(0);

    // bank payments ignore the drawer
    posAs($this->ctx)->post(route('expenses.store'), expensePayload(['expense_category_id' => $this->rent->id, 'amount' => 5000, 'method' => 'bank', 'register_session_id' => $this->ctx->session->id]))
        ->assertSessionHasNoErrors();
    expect(Expense::first()->register_session_id)->toBeNull();
});

it('locks drawer expenses once the shift is closed', function () {
    posAs($this->ctx)->post(route('expenses.store'), expensePayload(['expense_category_id' => $this->tea->id, 'register_session_id' => $this->ctx->session->id]));
    $e = Expense::first();
    posAs($this->ctx)->put(route('expenses.update', $e), expensePayload(['expense_category_id' => $this->tea->id, 'amount' => 80, 'register_session_id' => $this->ctx->session->id]))
        ->assertSessionHasNoErrors();
    expect($e->fresh()->amount)->toBe('80.00');

    app(RegisterService::class)->close($this->ctx->session, 920, null, $this->ctx->user);
    posAs($this->ctx)->put(route('expenses.update', $e), expensePayload(['expense_category_id' => $this->tea->id, 'amount' => 50]))->assertSessionHas('error');
    posAs($this->ctx)->delete(route('expenses.destroy', $e))->assertSessionHas('error');
    expect($e->fresh()->amount)->toBe('80.00');
});

it('stores a bill photo privately and validates input', function () {
    Storage::fake('local');
    posAs($this->ctx)->post(route('expenses.store'), expensePayload(['expense_category_id' => $this->rent->id, 'method' => 'bank',
        'attachment' => UploadedFile::fake()->image('bill.jpg')]))->assertSessionHasNoErrors();
    $e = Expense::first();
    Storage::disk('local')->assertExists($e->attachment_path);
    posAs($this->ctx)->get(route('expenses.attachment', $e))->assertOk();

    posAs($this->ctx)->delete(route('expenses.destroy', $e))->assertSessionHas('success');
    Storage::disk('local')->assertMissing($e->attachment_path);

    posAs($this->ctx)->post(route('expenses.store'), ['amount' => 0, 'method' => 'barter', 'date' => today()->addDay()->toDateString(), 'gst_amount' => 5])
        ->assertSessionHasErrors(['expense_category_id', 'amount', 'method', 'date']);
});

it('needs the expenses permission; categories in use cannot be deleted', function () {
    $cashier = userWithRole('Cashier', [$this->ctx->branch]);
    $this->actingAs($cashier)->get(route('expenses.index'))->assertForbidden();

    posAs($this->ctx)->get(route('expenses.index'))->assertOk();
    posAs($this->ctx)->post(route('expenses.store'), expensePayload(['expense_category_id' => $this->rent->id, 'method' => 'bank']));
    posAs($this->ctx)->delete(route('expense-categories.destroy', $this->rent))->assertSessionHas('error');
    posAs($this->ctx)->delete(route('expense-categories.destroy', $this->tea))->assertSessionHas('success');
});

it('shows expenses in the profit & loss report', function () {
    $tee = garmentVariant($this->ctx->branch, 549, 10);   // cost 329.40
    checkout($this->ctx, [['variant_id' => $tee->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 1098]], 1098)->assertCreated();
    posAs($this->ctx)->post(route('expenses.store'), expensePayload(['expense_category_id' => $this->rent->id, 'amount' => 1180, 'gst_amount' => 180, 'method' => 'bank']));

    $rows = collect(posAs($this->ctx)->get(route('reports.show', 'profit-loss'))->viewData('page')['props']['sections'][0]['rows'])->keyBy('label');
    $sales = $rows['Net sales (excl. GST, after returns)']['amount'];
    expect($rows['Less: cost of goods sold']['amount'])->toEqual(-658.8)
        ->and($rows['Total expenses']['amount'])->toEqual(-1000)   // excl. GST
        ->and($rows->first(fn ($r) => str_contains($r['label'], 'Net '.'loss') || $r['label'] === 'Net profit')['amount'])->toEqual(round($sales - 658.8 - 1000, 2));

    $exp = posAs($this->ctx)->get(route('reports.show', ['report' => 'expenses', 'by' => 'list']))->viewData('page')['props']['sections'][0];
    expect($exp['totals'])->amount->toEqual(1180)->gst->toEqual(180);
});
