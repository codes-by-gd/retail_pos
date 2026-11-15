@php
    $money = fn ($v) => number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $isTaxInvoice = (bool) $sale->customer_gstin;
    $title = $isTaxInvoice ? 'TAX INVOICE' : 'INVOICE';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sale->invoice_no }}</title>
    @include('print._style')
</head>
<body>
@include('print._toolbar')

@if ($format !== 'a4')
{{-- ================= Thermal receipt ================= --}}
<div class="page">
    <div class="center">
        @include('print._logo')
        <h1>{{ $business['name'] }}</h1>
        <div>{{ $branch->name }}</div>
        @if ($branch->address || $business['address'])<div class="small">{{ $branch->address ?: $business['address'] }}{{ ($branch->city ?: $business['city']) ? ', '.($branch->city ?: $business['city']) : '' }}</div>@endif
        @if ($branch->phone || $business['phone'])<div class="small">Ph: {{ $branch->phone ?: $business['phone'] }}</div>@endif
        @if ($gstin)<div class="small">GSTIN: {{ $gstin }}</div>@endif
        <div class="bold" style="margin-top:4px">{{ $title }}</div>
    </div>
    <hr>
    <table class="small">
        <tr><td>Bill: <b>{{ $sale->invoice_no }}</b></td><td class="right">{{ $sale->sold_at->format('d/m/Y h:i A') }}</td></tr>
        <tr><td colspan="2">Customer: {{ $sale->customer_name }}{{ $sale->customer_phone ? ' · '.$sale->customer_phone : '' }}</td></tr>
        @if ($sale->customer_gstin)<tr><td colspan="2">GSTIN: {{ $sale->customer_gstin }}</td></tr>@endif
        <tr><td colspan="2">Cashier: {{ $sale->cashier->name }}</td></tr>
    </table>
    <hr>
    <table>
        <tr class="bold"><td>Item</td><td class="right">Qty</td><td class="right">Rate</td><td class="right">Amt</td></tr>
        @foreach ($sale->items as $item)
            <tr><td colspan="4">{{ $item->product_name }}{{ $item->variant_name ? ' ('.$item->variant_name.')' : '' }}</td></tr>
            <tr>
                <td class="small muted">{{ $item->hsn_code ? 'HSN '.$item->hsn_code.' · ' : '' }}{{ (float) $item->gst_rate }}%</td>
                <td class="right">{{ $qty($item->quantity) }}</td>
                <td class="right">{{ $money($item->unit_price) }}</td>
                <td class="right">{{ $money((float) $item->unit_price * (float) $item->quantity) }}</td>
            </tr>
            @if ((float) $item->line_discount > 0)
                <tr><td colspan="3" class="small muted">&nbsp;Discount</td><td class="right small">-{{ $money($item->line_discount) }}</td></tr>
            @endif
            @if ($item->mrp && (float) $item->mrp > (float) $item->unit_price)
                <tr><td colspan="4" class="small muted">&nbsp;MRP {{ $money($item->mrp) }} · You save {{ $money(((float) $item->mrp - (float) $item->unit_price) * (float) $item->quantity) }}</td></tr>
            @endif
        @endforeach
    </table>
    <hr>
    <table class="totals">
        <tr><td>Items: {{ $sale->items->count() }} · Qty: {{ $qty($sale->items->sum('quantity')) }}</td><td class="right">{{ $money($sale->subtotal) }}</td></tr>
        @if ((float) $sale->line_discount_total > 0)<tr><td>Item discounts</td><td class="right">-{{ $money($sale->line_discount_total) }}</td></tr>@endif
        @if ((float) $sale->bill_discount_total > 0)<tr><td>Bill discount{{ $sale->bill_discount_type === 'percent' ? ' ('.(float) $sale->bill_discount_value.'%)' : '' }}</td><td class="right">-{{ $money($sale->bill_discount_total) }}</td></tr>@endif
        <tr class="small muted"><td>Taxable value</td><td class="right">{{ $money($sale->taxable_total) }}</td></tr>
        @if ($sale->is_inter_state)
            <tr class="small muted"><td>IGST</td><td class="right">{{ $money($sale->igst_total) }}</td></tr>
        @else
            <tr class="small muted"><td>CGST</td><td class="right">{{ $money($sale->cgst_total) }}</td></tr>
            <tr class="small muted"><td>SGST</td><td class="right">{{ $money($sale->sgst_total) }}</td></tr>
        @endif
        @if ((float) $sale->cess_total > 0)<tr class="small muted"><td>Cess</td><td class="right">{{ $money($sale->cess_total) }}</td></tr>@endif
        @if ((float) $sale->round_off != 0)<tr class="small muted"><td>Round off</td><td class="right">{{ $money($sale->round_off) }}</td></tr>@endif
        <tr class="grand"><td>TOTAL</td><td class="right">₹{{ $money($sale->grand_total) }}</td></tr>
    </table>
    <hr>
    <table class="totals small">
        @foreach ($payments as $p)
            <tr><td>{{ $p['label'] }}{{ $p['reference'] ? ' ('.$p['reference'].')' : '' }}</td><td class="right">{{ $money($p['amount']) }}</td></tr>
        @endforeach
        @if ((float) $sale->change_given > 0)<tr><td>Change returned</td><td class="right">{{ $money($sale->change_given) }}</td></tr>@endif
        @if ((float) $sale->account_amount > 0)<tr class="bold"><td>Balance due (on account)</td><td class="right">{{ $money($sale->account_amount) }}</td></tr>@endif
    </table>
    @if ($pointsBalance !== null)
        <hr>
        <table class="totals small">
            @if ($sale->points_redeemed)<tr><td>Points used</td><td class="right">{{ $sale->points_redeemed }}</td></tr>@endif
            @if ($sale->points_earned)<tr><td>Points earned</td><td class="right">+{{ $sale->points_earned }}</td></tr>@endif
            <tr class="bold"><td>Points balance</td><td class="right">{{ $pointsBalance }}</td></tr>
        </table>
    @endif
    @php($savings = $sale->items->sum(fn ($i) => $i->mrp ? max(0, ((float) $i->mrp - (float) $i->unit_price) * (float) $i->quantity) : 0) + (float) $sale->line_discount_total + (float) $sale->bill_discount_total)
    @if ($savings > 0)<hr><div class="center bold">You saved ₹{{ $money($savings) }} today!</div>@endif
    <hr>
    <div class="center small">
        @if ($invoice['footer'])<div>{{ $invoice['footer'] }}</div>@endif
    </div>
</div>
@else
{{-- ================= A4 tax invoice ================= --}}
<div class="page a4">
    <table style="border:0">
        <tr>
            <td style="border:0; width:60%">
                @include('print._logo')
                <h1>{{ $business['legal_name'] ?: $business['name'] }}</h1>
                <div>{{ $branch->name }}</div>
                <div class="small">{{ collect([$branch->address ?: $business['address'], $branch->city ?: $business['city'], $branch->pincode ?: $business['pincode']])->filter()->implode(', ') }}</div>
                <div class="small">{{ $branch->state_name ?? \App\Support\Gst\IndianStates::name($branch->state_code) }} ({{ $branch->state_code }})
                    @if ($branch->phone || $business['phone']) · Ph: {{ $branch->phone ?: $business['phone'] }} @endif</div>
                @if ($gstin)<div class="bold">GSTIN: {{ $gstin }}</div>@endif
            </td>
            <td style="border:0" class="right">
                <div class="bold" style="font-size:16px">{{ $title }}</div>
                <div>No: <b>{{ $sale->invoice_no }}</b></div>
                <div>Date: {{ $sale->sold_at->format('d M Y, h:i A') }}</div>
                <div>Place of supply: {{ $placeOfSupply }}</div>
            </td>
        </tr>
    </table>
    <table style="margin-top:8px">
        <tr><th style="width:50%">Billed to</th><th>Details</th></tr>
        <tr>
            <td>
                <b>{{ $sale->customer_name }}</b>
                @if (! $sale->customer->is_walk_in && $sale->customer->address)<div class="small">{{ collect([$sale->customer->address, $sale->customer->city, $sale->customer->pincode])->filter()->implode(', ') }}</div>@endif
                @if ($sale->customer_phone)<div class="small">Ph: {{ $sale->customer_phone }}</div>@endif
                @if ($sale->customer_gstin)<div class="bold small">GSTIN: {{ $sale->customer_gstin }}</div>@endif
            </td>
            <td class="small">
                Cashier: {{ $sale->cashier->name }}<br>
                Supply: {{ $sale->is_inter_state ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)' }}<br>
                Prices: {{ $sale->price_type === 'wholesale' ? 'Wholesale' : 'Retail' }}
            </td>
        </tr>
    </table>
    <table style="margin-top:8px">
        <thead>
            <tr><th>#</th><th>Item</th><th>HSN</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Disc.</th><th class="right">Taxable</th><th class="right">GST</th>
                @if ($sale->is_inter_state)<th class="right">IGST</th>@else<th class="right">CGST</th><th class="right">SGST</th>@endif
                <th class="right">Amount</th></tr>
        </thead>
        <tbody>
        @foreach ($sale->items as $n => $item)
            <tr>
                <td>{{ $n + 1 }}</td>
                <td>{{ $item->product_name }}@if ($item->variant_name)<div class="small muted">{{ $item->variant_name }}</div>@endif<div class="small muted">{{ $item->sku }}</div></td>
                <td>{{ $item->hsn_code }}</td>
                <td class="right">{{ $qty($item->quantity) }} {{ $item->unit }}</td>
                <td class="right">{{ $money($item->unit_price) }}</td>
                <td class="right">{{ $money((float) $item->line_discount + (float) $item->bill_discount_share) }}</td>
                <td class="right">{{ $money($item->taxable_value) }}</td>
                <td class="right">{{ (float) $item->gst_rate }}%</td>
                @if ($sale->is_inter_state)<td class="right">{{ $money($item->igst) }}</td>@else<td class="right">{{ $money($item->cgst) }}</td><td class="right">{{ $money($item->sgst) }}</td>@endif
                <td class="right">{{ $money($item->line_total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table style="margin-top:8px; border:0">
        <tr>
            <td style="border:0; width:58%">
                <table>
                    <thead><tr><th>HSN</th><th class="right">Rate</th><th class="right">Taxable</th>
                        @if ($sale->is_inter_state)<th class="right">IGST</th>@else<th class="right">CGST</th><th class="right">SGST</th>@endif</tr></thead>
                    @foreach ($hsnSummary as $h)
                        <tr><td>{{ $h['hsn'] }}</td><td class="right">{{ $h['rate'] }}%</td><td class="right">{{ $money($h['taxable']) }}</td>
                            @if ($sale->is_inter_state)<td class="right">{{ $money($h['igst']) }}</td>@else<td class="right">{{ $money($h['cgst']) }}</td><td class="right">{{ $money($h['sgst']) }}</td>@endif</tr>
                    @endforeach
                </table>
                <p class="small"><b>Amount in words:</b> {{ $words }}</p>
                @if ($pointsBalance !== null)<p class="small">Loyalty points: {{ $sale->points_redeemed ? 'used '.$sale->points_redeemed.' · ' : '' }}{{ $sale->points_earned ? 'earned '.$sale->points_earned.' · ' : '' }}balance <b>{{ $pointsBalance }}</b></p>@endif
                @foreach ($payments as $p)<div class="small">{{ $p['label'] }}{{ $p['reference'] ? ' ('.$p['reference'].')' : '' }}: ₹{{ $money($p['amount']) }}</div>@endforeach
                @if ((float) $sale->account_amount > 0)<div class="small bold">Balance due: ₹{{ $money($sale->account_amount) }}</div>@endif
            </td>
            <td style="border:0">
                <table class="totals">
                    <tr><td>Subtotal</td><td class="right">{{ $money($sale->subtotal) }}</td></tr>
                    @if ((float) $sale->line_discount_total + (float) $sale->bill_discount_total > 0)<tr><td>Discount</td><td class="right">-{{ $money((float) $sale->line_discount_total + (float) $sale->bill_discount_total) }}</td></tr>@endif
                    <tr><td>Taxable value</td><td class="right">{{ $money($sale->taxable_total) }}</td></tr>
                    @if ($sale->is_inter_state)<tr><td>IGST</td><td class="right">{{ $money($sale->igst_total) }}</td></tr>
                    @else<tr><td>CGST</td><td class="right">{{ $money($sale->cgst_total) }}</td></tr><tr><td>SGST</td><td class="right">{{ $money($sale->sgst_total) }}</td></tr>@endif
                    @if ((float) $sale->cess_total > 0)<tr><td>Cess</td><td class="right">{{ $money($sale->cess_total) }}</td></tr>@endif
                    @if ((float) $sale->round_off != 0)<tr><td>Round off</td><td class="right">{{ $money($sale->round_off) }}</td></tr>@endif
                    <tr class="grand"><td>Total</td><td class="right">₹{{ $money($sale->grand_total) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
    @if ($invoice['terms'])<p class="small"><b>Terms:</b> {!! nl2br(e($invoice['terms'])) !!}</p>@endif
    <table style="margin-top:24px; border:0"><tr>
        <td style="border:0" class="small muted">{{ $invoice['footer'] }}</td>
        <td style="border:0" class="right">For {{ $business['legal_name'] ?: $business['name'] }}<br><br><br>Authorised signatory</td>
    </tr></table>
</div>
@endif
</body>
</html>
