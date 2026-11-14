<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Models\SaleReturn;
use App\Support\AmountInWords;
use App\Support\Gst\IndianStates;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Printable invoice (thermal 58/80 mm or A4 tax invoice) and credit note. */
class PrintController extends Controller
{
    public function sale(Request $request, Sale $sale): View
    {
        abort_unless(current_branch()->allowedFor($request->user())->contains('id', $sale->branch_id), 403);
        $sale->load(['items', 'payments', 'cashier:id,name', 'branch', 'customer']);

        return view('print.sale', [
            ...$this->common($request, $sale->branch),
            'sale' => $sale,
            'payments' => $sale->payments->map(fn ($p) => ['label' => SalePayment::LABELS[$p->method] ?? $p->method, 'amount' => $p->amount, 'reference' => $p->reference]),
            'hsnSummary' => $this->hsnSummary($sale->items),
            'words' => AmountInWords::rupees($sale->grand_total),
            'placeOfSupply' => IndianStates::name($sale->place_of_supply).' ('.$sale->place_of_supply.')',
        ]);
    }

    public function saleReturn(Request $request, SaleReturn $saleReturn): View
    {
        abort_unless(current_branch()->allowedFor($request->user())->contains('id', $saleReturn->branch_id), 403);
        $saleReturn->load(['items.saleItem', 'sale', 'branch', 'user:id,name']);

        return view('print.credit-note', [
            ...$this->common($request, $saleReturn->branch),
            'return' => $saleReturn,
            'words' => AmountInWords::rupees($saleReturn->total),
        ]);
    }

    private function common(Request $request, $branch): array
    {
        $format = $request->query('format', settings('pos.receipt_size'));

        return [
            'format' => in_array($format, ['58mm', '80mm', 'a4'], true) ? $format : '80mm',
            'autoprint' => $request->boolean('autoprint'),
            'business' => settings()->group('business'),
            'invoice' => settings()->group('invoice'),
            'branch' => $branch,
            'gstin' => $branch->gstin ?: settings('business.gstin'),
        ];
    }

    /** Taxable value and tax per HSN code and rate (required on GST invoices). */
    private function hsnSummary($items): array
    {
        return $items->groupBy(fn ($i) => ($i->hsn_code ?? '—').'|'.$i->gst_rate)
            ->map(fn ($g, $key) => [
                'hsn' => explode('|', $key)[0],
                'rate' => (float) $g->first()->gst_rate,
                'taxable' => $g->sum(fn ($i) => (float) $i->taxable_value),
                'cgst' => $g->sum(fn ($i) => (float) $i->cgst),
                'sgst' => $g->sum(fn ($i) => (float) $i->sgst),
                'igst' => $g->sum(fn ($i) => (float) $i->igst),
            ])->values()->all();
    }
}
