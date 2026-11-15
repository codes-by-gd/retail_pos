@php
    $money = fn ($v) => number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $branch = $order->branch;
    $s = $order->supplier;
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $order->reference_no }}</title>@include('print._a4head')</head>
<body>
<div class="no-print"><button type="button" onclick="window.print()">Print</button></div>
<div class="page a4">
    @include('print._letterhead', ['title' => 'PURCHASE ORDER', 'number' => $order->reference_no, 'date' => $order->order_date->format('d M Y'),
        'extra' => $order->expected_date ? '<div>Deliver by: '.$order->expected_date->format('d M Y').'</div>' : ''])
    <table style="margin-top:8px"><tr><th style="width:20%">Supplier</th><td><b>{{ $s->company_name ?: $s->name }}</b> · {{ $s->phone }}
        <div class="small">{{ collect([$s->address, $s->city, $s->pincode])->filter()->implode(', ') }}</div>@if ($s->gstin)<div class="small">GSTIN {{ $s->gstin }}</div>@endif</td></tr>
        <tr><th>Deliver to</th><td>{{ $branch->name }}, {{ collect([$branch->address ?: $business['address'], $branch->city ?: $business['city']])->filter()->implode(', ') }}</td></tr></table>
    <table style="margin-top:8px">
        <thead><tr><th>#</th><th>Item</th><th>SKU</th><th class="right">Qty</th><th class="right">Rate (excl. GST)</th><th class="right">Amount</th></tr></thead>
        @foreach ($order->items as $n => $i)
            <tr><td>{{ $n + 1 }}</td><td>{{ $i->variant->fullName() }}</td><td>{{ $i->variant->sku }}</td>
                <td class="right">{{ $qty($i->quantity) }} {{ $i->variant->product->unit->short_name }}</td><td class="right">{{ $money($i->unit_cost) }}</td>
                <td class="right">{{ $money((float) $i->quantity * (float) $i->unit_cost) }}</td></tr>
        @endforeach
        <tr class="bold"><td colspan="5" class="right">Estimated total (before GST)</td><td class="right">₹{{ $money($order->estimated_total) }}</td></tr>
    </table>
    @if ($order->notes)<p class="small"><b>Notes:</b> {!! nl2br(e($order->notes)) !!}</p>@endif
    <p class="small muted">Please mention our PO number on your invoice. GST extra as applicable.</p>
    <table style="margin-top:30px; border:0"><tr><td style="border:0" class="small muted">Raised by {{ $order->user->name }}</td>
        <td style="border:0" class="right">For {{ $business['legal_name'] ?: $business['name'] }}<br><br><br>Authorised signatory</td></tr></table>
</div>
</body></html>
