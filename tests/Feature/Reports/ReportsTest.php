<?php

use App\Modules\Contacts\Models\Contact;
use App\Modules\Reports\ReportRegistry;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturnItem;
use App\Modules\Settings\Models\Branch;
use App\Rules\Gstin;
use App\Support\CurrentBranch;

beforeEach(function () {
    $this->ctx = posSetup('Manager');
    $this->shirt = garmentVariant($this->ctx->branch, 1799, 10);   // 5% GST, cost 1079.40
    $this->tee = garmentVariant($this->ctx->branch, 549, 20);      // 5% GST, cost 329.40
});

function reportSell(object $ctx, array $items, float $total, ?int $customerId = null, string $method = 'cash'): Sale
{
    checkout($ctx, $items, [['method' => $method, 'amount' => $total]], $total, ['customer_id' => $customerId])->assertCreated();

    return Sale::with('items')->latest('id')->first();
}

/** Props of a report page. */
function reportProps(object $ctx, string $key, array $query = []): array
{
    return posAs($ctx)->get(route('reports.show', ['report' => $key, ...$query]))->assertOk()->viewData('page')['props'];
}

function sectionRows(array $props, int $i = 0): array
{
    return $props['sections'][$i]['rows'];
}

it('shows reports only to roles with a report permission', function () {
    $cashier = userWithRole('Cashier', [$this->ctx->branch]);
    $this->actingAs($cashier)->get(route('reports.index'))->assertForbidden();

    $keeper = userWithRole('Stock Keeper', [$this->ctx->branch]);
    $groups = $this->actingAs($keeper)->get(route('reports.index'))->assertOk()->viewData('page')['props']['groups'];
    expect(collect($groups)->pluck('group')->all())->toBe(['Inventory & purchases']);
    $this->actingAs($keeper)->get(route('reports.show', 'stock-valuation'))->assertOk();
    $this->actingAs($keeper)->get(route('reports.show', 'profit'))->assertForbidden();
    $this->actingAs($keeper)->get(route('reports.export', ['report' => 'gstr-1', 'format' => 'csv']))->assertForbidden();
    $this->actingAs($keeper)->get(route('reports.show', 'no-such-report'))->assertNotFound();
});

it('renders and exports every report', function () {
    reportSell($this->ctx, [['variant_id' => $this->shirt->id, 'quantity' => 1], ['variant_id' => $this->tee->id, 'quantity' => 2]], 2897);

    foreach (app(ReportRegistry::class)->all() as $report) {
        $props = reportProps($this->ctx, $report->key());
        expect($props['sections'])->not->toBeEmpty();
        posAs($this->ctx)->get(route('reports.export', ['report' => $report->key(), 'format' => 'csv']))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        posAs($this->ctx)->get(route('reports.export', ['report' => $report->key(), 'format' => 'xlsx']))->assertOk();
    }
    posAs($this->ctx)->get(route('reports.export', ['report' => 'gstr-1', 'format' => 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
    posAs($this->ctx)->get(route('reports.export', ['report' => 'gstr-1', 'format' => 'doc']))->assertSessionHasErrors('format');
});

it('nets returns out of sales and keeps the cost of damaged returns in profit', function () {
    $sale = reportSell($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 3]], 1647);
    posAs($this->ctx)->postJson(route('pos.returns.store'), ['sale_id' => $sale->id, 'refund_method' => 'cash', 'reason' => 'Torn',
        'items' => [['sale_item_id' => $sale->items[0]->id, 'quantity' => 1, 'restock' => false]]])->assertCreated();

    $summary = reportProps($this->ctx, 'sales-summary');
    expect($summary['sections'][0]['totals'])->bills->toEqual(1)->total->toEqual(1647)->returns->toEqual(549)->net_sales->toEqual(1098);

    $item = collect(sectionRows(reportProps($this->ctx, 'sales-by-item', ['by' => 'variant'])))->first();
    expect($item)->sold_qty->toEqual(3)->ret_qty->toEqual(1)->net_qty->toEqual(2)->net_total->toEqual(1098);

    // Damaged piece: revenue given back, but its cost is not (3 × 329.40 = 988.20 stays as cost)
    $profit = reportProps($this->ctx, 'profit')['sections'][0]['totals'];
    expect($profit['net_cost'])->toEqual(988.2)
        ->and($profit['profit'])->toEqual(round($profit['net_taxable'] - 988.2, 2))
        ->and($profit['margin'])->toEqual(round($profit['profit'] / $profit['net_taxable'] * 100, 1));
});

it('splits GSTR-1 into B2B and B2C, nets credit notes and counts cancelled invoices', function () {
    $gstin = '27AAKFK4321B1Z'.Gstin::checksum('27AAKFK4321B1Z');
    $boutique = Contact::factory()->create(['type' => Contact::CUSTOMER, 'gstin' => $gstin, 'state_code' => '27', 'credit_limit' => 0]);

    reportSell($this->ctx, [['variant_id' => $this->shirt->id, 'quantity' => 2]], 3598, $boutique->id);        // B2B, IGST
    $b2c = reportSell($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 2]], 1098);                  // B2C small
    $cancelled = reportSell($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 1]], 549);
    posAs($this->ctx)->post(route('sales.cancel', $cancelled), ['reason' => 'Wrong item'])->assertSessionHas('success');
    posAs($this->ctx)->postJson(route('pos.returns.store'), ['sale_id' => $b2c->id, 'refund_method' => 'cash',
        'items' => [['sale_item_id' => $b2c->items[0]->id, 'quantity' => 1, 'restock' => true]]])->assertCreated();

    $props = reportProps($this->ctx, 'gstr-1', ['from' => today()->toDateString(), 'to' => today()->toDateString()]);
    [$b2b, $b2cl, $b2cs, $cdnr, $cdnur, $hsn, $docs] = array_map(fn ($s) => $s['rows'], $props['sections']);

    expect($b2b)->toHaveCount(1)
        ->and($b2b[0])->gstin->toBe($gstin)->pos->toBe('27-Maharashtra')->rate->toEqual(5)->value->toEqual(3598)->cgst->toEqual(0)
        ->and($b2b[0]['igst'])->toBeGreaterThan(0)
        ->and($b2cl)->toBe([])->and($cdnr)->toBe([])->and($cdnur)->toBe([])
        // one tee left after the return (taxable 1,045.71 − credit note 522.86)
        ->and($b2cs)->toHaveCount(1)->and($b2cs[0])->pos->toBe('24-Gujarat')->taxable->toEqual(522.85)->igst->toEqual(0)
        // tax = sale-line GST − credit-note-line GST (the note's rupee round-off is not GST)
        ->and(round($b2cs[0]['cgst'] + $b2cs[0]['sgst'], 2))->toEqual(round((float) $b2c->items[0]->cgst + (float) $b2c->items[0]->sgst
            - (float) SaleReturnItem::sum(DB::raw('cgst + sgst')), 2))
        ->and(collect($hsn)->firstWhere('kind', 'B2C')['qty'])->toEqual(1)
        ->and(collect($hsn)->firstWhere('kind', 'B2B')['qty'])->toEqual(2)
        ->and($docs[0])->nature->toBe('Invoices for outward supply')->total->toEqual(3)->cancelled->toEqual(1)->net->toEqual(2)
        ->and($docs[1])->nature->toBe('Credit notes')->total->toEqual(1);

    // HSN totals = B2B + B2C taxable
    expect($props['sections'][5]['totals']['taxable'])->toEqual(round($b2b[0]['taxable'] + $b2cs[0]['taxable'], 2));
});

it('takes ITC in GSTR-3B only from registered suppliers', function () {
    $gstin = '24AAFFS1234K1Z'.Gstin::checksum('24AAFFS1234K1Z');
    $registered = Contact::factory()->supplier()->create(['state_code' => '24', 'gstin' => $gstin]);
    $unregistered = Contact::factory()->supplier()->create(['state_code' => '24', 'gstin' => null]);
    foreach ([$registered, $unregistered] as $i => $s) {
        posAs($this->ctx)->post(route('purchases.store'), ['supplier_id' => $s->id, 'supplier_invoice_no' => "B-{$i}", 'received_date' => today()->toDateString(),
            'items' => [['variant_id' => $this->tee->id, 'quantity' => 10, 'unit_cost' => 300]], 'other_charges' => 0])->assertSessionHasNoErrors();
    }

    $itc = reportProps($this->ctx, 'gstr-3b', ['from' => today()->toDateString(), 'to' => today()->toDateString()])['sections'][2]['rows'];
    // 10 × 300 = 3,000 at 5% → CGST 75 + SGST 75, from the registered supplier only
    expect($itc[0])->taxable->toEqual(3000)->cgst->toEqual(75)->sgst->toEqual(75)->igst->toEqual(0)
        ->and($itc[2])->cgst->toEqual(75);
});

it('never shows branches the user is not assigned to', function () {
    $other = Branch::factory()->create(['code' => 'BR2', 'state_code' => '24']);
    $cashierLike = userWithRole('Manager', [$this->ctx->branch]);

    $props = $this->actingAs($cashierLike)->withSession([CurrentBranch::SESSION_KEY => $this->ctx->branch->id])
        ->get(route('reports.show', ['report' => 'sales-summary', 'branch' => $other->id]))->viewData('page')['props'];
    expect($props['filters']['branch'])->toBe((string) $this->ctx->branch->id)->and($props['branchLabel'])->toBe($this->ctx->branch->name);

    $props = $this->actingAs($cashierLike)->get(route('reports.show', ['report' => 'sales-summary', 'branch' => 'all']))->viewData('page')['props'];
    expect($props['branchLabel'])->toBe($this->ctx->branch->name)->and(collect($props['branches'])->pluck('value')->all())->toBe([(string) $this->ctx->branch->id]);
});

it('fills the dashboard with today\'s figures and hides profit without permission', function () {
    $sale = reportSell($this->ctx, [['variant_id' => $this->tee->id, 'quantity' => 2]], 1098, method: 'upi');
    posAs($this->ctx)->postJson(route('pos.returns.store'), ['sale_id' => $sale->id, 'refund_method' => 'cash',
        'items' => [['sale_item_id' => $sale->items[0]->id, 'quantity' => 1, 'restock' => true]]])->assertCreated();

    $data = posAs($this->ctx)->get(route('dashboard'))->assertOk()->viewData('page')['props']['data'];
    expect($data['kpis'])->net_sales->value->toEqual(549)->bills->value->toEqual(1)->average->value->toEqual(1098)->pieces->value->toEqual(1)
        ->and($data['kpis']['returns']['value'])->toEqual(549)->and($data['kpis']['returns']['count'])->toEqual(1)
        ->and($data['kpis']['profit']['value'])->toBeGreaterThan(0)
        ->and($data['paymentModes'][0])->method->toBe('upi')->share->toEqual(100)
        ->and(collect($data['trend'])->sum('value'))->toEqual(549);

    // secondary panels arrive as a deferred prop
    posAs($this->ctx)->get(route('dashboard'))->assertInertia(fn ($p) => $p->missing('details')
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('details.topProducts.0.qty', 1)
            ->where('details.drawers.0.expected', 451)));   // float 1000 − cash refund 549

    $cashier = userWithRole('Cashier', [$this->ctx->branch]);
    $data = $this->actingAs($cashier)->get(route('dashboard', ['branch' => 'all', 'period' => '7d']))->viewData('page')['props'];
    expect($data['allBranches'])->toBeFalse()->and($data['data']['kpis']['profit'])->toBeNull()->and($data['data']['trend'])->toHaveCount(7);
});
