<?php

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactPayment;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseReturn;
use App\Modules\Sales\Models\Quotation;
use App\Rules\Gstin;

beforeEach(function () {
    $this->ctx = posSetup('Manager');
    $this->shirt = garmentVariant($this->ctx->branch, 1799, 0);
    $this->tee = garmentVariant($this->ctx->branch, 549, 0);
    $this->local = Contact::factory()->supplier()->create(['state_code' => '24']);
    $gstin = '29AACCA5678L1Z'.Gstin::checksum('29AACCA5678L1Z');
    $this->karnataka = Contact::factory()->supplier()->create(['state_code' => '29', 'gstin' => $gstin]);
});

function purchasePayload(int $supplierId, array $items, array $extra = []): array
{
    return ['supplier_id' => $supplierId, 'supplier_invoice_no' => 'ST/'.random_int(100, 999), 'supplier_invoice_date' => now()->toDateString(),
        'received_date' => now()->toDateString(), 'items' => $items, 'other_charges' => 0, ...$extra];
}

it('receives goods: stock at landed cost, GST, supplier credited', function () {
    posAs($this->ctx)->post(route('purchases.store'), purchasePayload($this->local->id, [
        ['variant_id' => $this->shirt->id, 'quantity' => 10, 'unit_cost' => 1000],
        ['variant_id' => $this->tee->id, 'quantity' => 20, 'unit_cost' => 300, 'discount_type' => 'percent', 'discount_value' => 10],
    ], ['other_charges' => 400, 'update_cost_price' => true]))->assertSessionHasNoErrors();

    $p = Purchase::with('items')->first();
    // taxable 10,000 + 5,400; 5% GST = 500 + 270; + freight 400 = 16,570
    expect($p)->reference_no->toStartWith('PUR/BR1/')->taxable_total->toBe('15400.00')->cgst_total->toBe('385.00')->sgst_total->toBe('385.00')
        ->grand_total->toBe('16570.00')->due_amount->toBe('16570.00')->payment_status->toBe('due')
        // freight shared by taxable: 400 × 10000/15400 = 259.74 → landed (10000 + 259.74)/10
        ->and($p->items[0]->landed_unit_cost)->toBe('1025.9740')
        ->and(app(StockService::class)->quantity($this->shirt->id, $this->ctx->branch->id))->toBe('10.000')
        ->and(app(StockService::class)->averageCost($this->shirt->id, $this->ctx->branch->id))->toBe('1025.9740')
        ->and($this->tee->fresh()->purchase_price)->toBe('270.00')
        ->and($this->local->balance())->toBe('-16570.00');
});

it('uses IGST for an out-of-state supplier and records a part payment', function () {
    posAs($this->ctx)->post(route('purchases.store'), purchasePayload($this->karnataka->id, [['variant_id' => $this->shirt->id, 'quantity' => 4, 'unit_cost' => 2600]],
        ['payment' => ['amount' => 5000, 'method' => 'bank', 'reference' => 'NEFT123']]))->assertSessionHasNoErrors();

    $p = Purchase::first();
    // 2,600 per piece > ₹2,500 → 18%
    expect($p)->is_inter_state->toBeTrue()->igst_total->toBe('1872.00')->grand_total->toBe('12272.00')
        ->paid_amount->toBe('5000.00')->due_amount->toBe('7272.00')->payment_status->toBe('partial')
        ->and($this->karnataka->balance())->toBe('-7272.00');
});

it('rejects the same supplier bill twice', function () {
    $payload = purchasePayload($this->local->id, [['variant_id' => $this->tee->id, 'quantity' => 1, 'unit_cost' => 300]], ['supplier_invoice_no' => 'ST/555']);
    posAs($this->ctx)->post(route('purchases.store'), $payload)->assertSessionHasNoErrors();
    posAs($this->ctx)->post(route('purchases.store'), $payload)->assertSessionHasErrors('supplier_invoice_no');
});

it('receives a purchase order in parts', function () {
    posAs($this->ctx)->post(route('purchase-orders.store'), ['supplier_id' => $this->local->id, 'order_date' => now()->toDateString(), 'place' => true,
        'items' => [['variant_id' => $this->shirt->id, 'quantity' => 10, 'unit_cost' => 1000], ['variant_id' => $this->tee->id, 'quantity' => 5, 'unit_cost' => 300]]])
        ->assertSessionHasNoErrors();
    $order = PurchaseOrder::with('items')->first();
    expect($order)->status->toBe('ordered')->reference_no->toStartWith('PO/BR1/')->estimated_total->toBe('11500.00');

    $line = fn ($i, $qty) => ['variant_id' => $order->items[$i]->product_variant_id, 'purchase_order_item_id' => $order->items[$i]->id, 'quantity' => $qty, 'unit_cost' => $order->items[$i]->unit_cost];
    posAs($this->ctx)->post(route('purchases.store'), purchasePayload($this->local->id, [$line(0, 6)], ['purchase_order_id' => $order->id]))->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe('partially_received');

    posAs($this->ctx)->post(route('purchases.store'), purchasePayload($this->local->id, [$line(0, 5)], ['purchase_order_id' => $order->id]))
        ->assertSessionHasErrors('items.0.quantity');   // only 4 left

    posAs($this->ctx)->post(route('purchases.store'), purchasePayload($this->local->id, [$line(0, 4), $line(1, 5)], ['purchase_order_id' => $order->id]))->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe('received');
});

it('returns goods to the supplier with a debit note', function () {
    posAs($this->ctx)->post(route('purchases.store'), purchasePayload($this->local->id, [['variant_id' => $this->tee->id, 'quantity' => 10, 'unit_cost' => 300]]));
    $p = Purchase::with('items')->first();   // 3,000 + 150 GST = 3,150

    posAs($this->ctx)->post(route('purchases.returns.store', $p), ['items' => [['purchase_item_id' => $p->items[0]->id, 'quantity' => 4]], 'reason' => 'Stitching defect'])
        ->assertSessionHas('success');
    $dn = PurchaseReturn::first();
    expect($dn)->reference_no->toStartWith('DN/BR1/')->total->toBe('1260.00')
        ->and(app(StockService::class)->quantity($this->tee->id, $this->ctx->branch->id))->toBe('6.000')
        ->and($p->fresh())->due_amount->toBe('1890.00')
        ->and($this->local->balance())->toBe('-1890.00');

    posAs($this->ctx)->post(route('purchases.returns.store', $p), ['items' => [['purchase_item_id' => $p->items[0]->id, 'quantity' => 7]]])
        ->assertSessionHasErrors('items.0.quantity');
    posAs($this->ctx)->post(route('purchases.returns.store', $p), ['items' => [['purchase_item_id' => $p->items[0]->id, 'quantity' => 6]]]);
    expect((float) PurchaseReturn::sum('total'))->toBe(3150.0)->and($this->local->balance())->toBe('0.00');
});

it('pays a supplier against the oldest bills', function () {
    posAs($this->ctx)->post(route('purchases.store'), purchasePayload($this->local->id, [['variant_id' => $this->tee->id, 'quantity' => 10, 'unit_cost' => 300]]));
    posAs($this->ctx)->post(route('purchases.store'), purchasePayload($this->local->id, [['variant_id' => $this->tee->id, 'quantity' => 10, 'unit_cost' => 300]]));
    [$a, $b] = Purchase::orderBy('id')->get();

    posAs($this->ctx)->post(route('contacts.payments.store', $this->local), ['direction' => 'out', 'amount' => 4000, 'method' => 'cheque', 'reference' => '000123', 'date' => now()->toDateString()]);
    expect($a->fresh())->payment_status->toBe('paid')->and($b->fresh())->due_amount->toBe('2300.00');
});

it('renders the Phase 4 pages', function () {
    posAs($this->ctx)->post(route('purchase-orders.store'), ['supplier_id' => $this->local->id, 'order_date' => now()->toDateString(), 'place' => true,
        'items' => [['variant_id' => $this->tee->id, 'quantity' => 5, 'unit_cost' => 300]]]);
    posAs($this->ctx)->post(route('purchases.store'), purchasePayload($this->local->id, [['variant_id' => $this->tee->id, 'quantity' => 5, 'unit_cost' => 300]],
        ['payment' => ['amount' => 100, 'method' => 'cash']]));
    posAs($this->ctx)->post(route('purchases.returns.store', Purchase::first()), ['items' => [['purchase_item_id' => Purchase::first()->items()->first()->id, 'quantity' => 1]]]);
    posAs($this->ctx)->post(route('quotations.store'), quotePayload(['items' => [['variant_id' => $this->tee->id, 'quantity' => 1]]]));
    $order = PurchaseOrder::first();
    $q = Quotation::first();
    $payment = ContactPayment::first();

    foreach ([
        route('purchase-orders.index') => 'Purchases/Orders/Index', route('purchase-orders.create') => 'Purchases/Orders/Form',
        route('purchase-orders.show', $order) => 'Purchases/Orders/Show', route('purchases.index') => 'Purchases/Index',
        route('purchases.create') => 'Purchases/Form', route('purchases.create', ['order' => $order->id]) => 'Purchases/Form',
        route('purchases.show', Purchase::first()) => 'Purchases/Show', route('quotations.index') => 'Quotations/Index',
        route('quotations.create') => 'Quotations/Form', route('quotations.show', $q) => 'Quotations/Show', route('quotations.edit', $q) => 'Quotations/Form',
        route('payments.index') => 'Payments/Index', route('suppliers.show', $this->local) => 'Contacts/Show',
    ] as $url => $component) {
        posAs($this->ctx)->get($url)->assertOk()->assertInertia(fn ($p) => $p->component($component));
    }
    foreach ([route('purchase-orders.print', $order), route('quotations.print', $q), route('payments.print', $payment),
        route('purchase-returns.print', PurchaseReturn::first())] as $url) {
        posAs($this->ctx)->get($url)->assertOk();
    }
});
