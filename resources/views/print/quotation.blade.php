@php
    $money = fn ($v) => number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $q->reference_no }}</title>@include('print._a4head')</head>
<body>
<div class="no-print"><button type="button" onclick="window.print()">Print</button></div>
<div class="page a4">
    @include('print._letterhead', ['title' => 'QUOTATION', 'number' => $q->reference_no, 'date' => $q->date->format('d M Y'),
        'extra' => ($q->valid_until ? '<div>Valid until: '.$q->valid_until->format('d M Y').'</div>' : '').'<div>Place of supply: '.e($placeOfSupply).'</div>'])
    <table style="margin-top:8px"><tr><th style="width:20%">To</th><td><b>{{ $q->customer_name }}</b>{{ $q->customer_phone ? ' · '.$q->customer_phone : '' }}
        @if ($q->customer?->gstin)<br><span class="small">GSTIN {{ $q->customer->gstin }}</span>@endif</td></tr></table>
    <table style="margin-top:8px">
        <thead><tr><th>#</th><th>Item</th><th>HSN</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Disc.</th><th class="right">Taxable</th><th class="right">GST</th><th class="right">Amount</th></tr></thead>
        @foreach ($q->items as $n => $i)
            <tr><td>{{ $n + 1 }}</td><td>{{ $i->product_name }}@if ($i->variant_name)<div class="small muted">{{ $i->variant_name }}</div>@endif</td><td>{{ $i->hsn_code }}</td>
                <td class="right">{{ $qty($i->quantity) }} {{ $i->unit }}</td><td class="right">{{ $money($i->unit_price) }}</td>
                <td class="right">{{ $money((float) $i->line_discount + (float) $i->bill_discount_share) }}</td><td class="right">{{ $money($i->taxable_value) }}</td>
                <td class="right">{{ (float) $i->gst_rate }}%</td><td class="right">{{ $money($i->line_total) }}</td></tr>
        @endforeach
    </table>
    <table style="margin-top:8px; border:0"><tr>
        <td style="border:0; width:55%" class="small"><b>Amount in words:</b> {{ $words }}@if ($q->notes)<p><b>Notes:</b> {!! nl2br(e($q->notes)) !!}</p>@endif
        </td>
        <td style="border:0"><table class="totals">
            <tr><td>Subtotal</td><td class="right">{{ $money($q->subtotal) }}</td></tr>
            @if ((float) $q->line_discount_total + (float) $q->bill_discount_total > 0)<tr><td>Discount</td><td class="right">-{{ $money((float) $q->line_discount_total + (float) $q->bill_discount_total) }}</td></tr>@endif
            <tr><td>Taxable value</td><td class="right">{{ $money($q->taxable_total) }}</td></tr>
            @if ($q->is_inter_state)<tr><td>IGST</td><td class="right">{{ $money($q->igst_total) }}</td></tr>
            @else<tr><td>CGST</td><td class="right">{{ $money($q->cgst_total) }}</td></tr><tr><td>SGST</td><td class="right">{{ $money($q->sgst_total) }}</td></tr>@endif
            @if ((float) $q->round_off != 0)<tr><td>Round off</td><td class="right">{{ $money($q->round_off) }}</td></tr>@endif
            <tr class="grand"><td>Total</td><td class="right">₹{{ $money($q->grand_total) }}</td></tr>
        </table></td>
    </tr></table>
    <table style="margin-top:30px; border:0"><tr><td style="border:0" class="small muted">Prepared by {{ $q->user->name }}</td>
        <td style="border:0" class="right">For {{ $business['legal_name'] ?: $business['name'] }}<br><br><br>Authorised signatory</td></tr></table>
</div>
</body></html>
