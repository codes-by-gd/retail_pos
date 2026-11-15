@php
    $money = fn ($v) => number_format((float) $v, 2);
    $in = $payment->direction === 'in';
    $branch = $payment->branch;
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $payment->reference_no }}</title>@include('print._a4head')</head>
<body>
<div class="no-print"><button type="button" onclick="window.print()">Print</button></div>
<div class="page a4">
    @include('print._letterhead', ['title' => $in ? 'PAYMENT RECEIPT' : 'PAYMENT VOUCHER', 'number' => $payment->reference_no, 'date' => $payment->date->format('d M Y')])
    <table style="margin-top:12px">
        <tr><th style="width:30%">{{ $in ? 'Received from' : 'Paid to' }}</th><td><b>{{ $payment->contact->company_name ?: $payment->contact->name }}</b> ({{ $payment->contact->code }})
            @if ($payment->contact->gstin)<br><span class="small">GSTIN {{ $payment->contact->gstin }}</span>@endif</td></tr>
        <tr><th>Amount</th><td class="bold" style="font-size:16px">₹{{ $money($payment->amount) }}</td></tr>
        <tr><th>In words</th><td>{{ $words }}</td></tr>
        <tr><th>Mode</th><td>{{ $method }}{{ $payment->reference ? ' · Ref '.$payment->reference : '' }}</td></tr>
        @if ($payment->note)<tr><th>Note</th><td>{{ $payment->note }}</td></tr>@endif
    </table>
    @if ($payment->allocations->count())
        <table style="margin-top:12px">
            <thead><tr><th>Against bill</th><th class="right">Amount</th></tr></thead>
            @foreach ($payment->allocations as $a)
                <tr><td>{{ $a->allocatable->invoice_no ?? ($a->allocatable->supplier_invoice_no ?: $a->allocatable->reference_no) }}</td><td class="right">{{ $money($a->amount) }}</td></tr>
            @endforeach
            @if ((float) $payment->unallocated > 0)<tr><td>On account (earlier balance / advance)</td><td class="right">{{ $money($payment->unallocated) }}</td></tr>@endif
        </table>
    @endif
    <table style="margin-top:40px; border:0"><tr>
        <td style="border:0" class="small muted">Recorded by {{ $payment->user->name }}</td>
        <td style="border:0" class="right">For {{ $business['legal_name'] ?: $business['name'] }}<br><br><br>Authorised signatory</td>
    </tr></table>
</div>
</body></html>
