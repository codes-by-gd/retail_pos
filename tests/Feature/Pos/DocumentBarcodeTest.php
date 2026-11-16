<?php

use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;

it('prints a scannable barcode of the invoice and credit-note number', function () {
    $ctx = posSetup('Manager');
    $tee = garmentVariant($ctx->branch, 549, 10);
    checkout($ctx, [['variant_id' => $tee->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 1098]], 1098)->assertCreated();
    $sale = Sale::with('items')->first();

    foreach (['80mm', '58mm', 'a4'] as $format) {
        posAs($ctx)->get(route('sales.print', ['sale' => $sale, 'format' => $format]))->assertOk()
            ->assertSee('class="doc-barcode', false)
            ->assertSee('aria-label="'.$sale->invoice_no.'"', false)
            ->assertDontSee('<?xml', false);
    }

    // the scanned value is exactly what the returns screen looks up
    posAs($ctx)->getJson(route('pos.returns.lookup', ['invoice_no' => $sale->invoice_no]))->assertOk()->assertJsonPath('sale.invoice_no', $sale->invoice_no);

    posAs($ctx)->postJson(route('pos.returns.store'), ['sale_id' => $sale->id, 'refund_method' => 'exchange',
        'items' => [['sale_item_id' => $sale->items[0]->id, 'quantity' => 1, 'restock' => true]]])->assertCreated();
    $note = SaleReturn::first();
    posAs($ctx)->get(route('sale-returns.print', $note))->assertOk()->assertSee('aria-label="'.$note->credit_note_no.'"', false);
    posAs($ctx)->getJson(route('pos.exchange-credit', ['credit_note_no' => $note->credit_note_no]))->assertOk();
});
