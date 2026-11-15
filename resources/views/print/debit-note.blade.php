@php
    $money = fn ($v) => number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $branch = $return->branch;
    $s = $return->supplier;
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $return->reference_no }}</title>@include('print._a4head')</head>
<body>
<div class="no-print"><button type="button" onclick="window.print()">Print</button></div>
<div class="page a4">
    @include('print._letterhead', ['title' => 'DEBIT NOTE', 'number' => $return->reference_no, 'date' => $return->date->format('d M Y'),
        'extra' => '<div>Against bill: '.e($return->purchase->supplier_invoice_no ?: $return->purchase->reference_no).'</div>'])
    <table style="margin-top:8px"><tr><th style="width:20%">Supplier</th><td><b>{{ $s->company_name ?: $s->name }}</b>@if ($s->gstin) · GSTIN {{ $s->gstin }}@endif</td></tr></table>
    <table style="margin-top:8px">
        <thead><tr><th>#</th><th>Item</th><th class="right">Qty</th><th class="right">Taxable</th><th class="right">GST</th><th class="right">Amount</th></tr></thead>
        @foreach ($return->items as $n => $i)
            <tr><td>{{ $n + 1 }}</td><td>{{ $i->purchaseItem->displayName() }}</td><td class="right">{{ $qty($i->quantity) }}</td>
                <td class="right">{{ $money($i->taxable_value) }}</td><td class="right">{{ $money((float) $i->cgst + (float) $i->sgst + (float) $i->igst + (float) $i->cess) }}</td>
                <td class="right">{{ $money($i->line_total) }}</td></tr>
        @endforeach
        <tr class="bold"><td colspan="5" class="right">Total</td><td class="right">₹{{ $money($return->total) }}</td></tr>
    </table>
    <p class="small"><b>Amount in words:</b> {{ $words }}</p>
    @if ($return->reason)<p class="small"><b>Reason:</b> {{ $return->reason }}</p>@endif
    <table style="margin-top:30px; border:0"><tr><td style="border:0" class="small muted">Prepared by {{ $return->user->name }}</td>
        <td style="border:0" class="right">For {{ $business['legal_name'] ?: $business['name'] }}<br><br><br>Authorised signatory</td></tr></table>
</div>
</body></html>
