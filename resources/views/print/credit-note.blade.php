@php
    $money = fn ($v) => number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $refund = ['cash' => 'Cash refund', 'card' => 'Refund to card', 'upi' => 'Refund by UPI', 'account' => 'Credited to customer account', 'exchange' => 'Exchange credit (use against next bill)'][$return->refund_method] ?? $return->refund_method;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $return->credit_note_no }}</title>
    @include('print._style')
</head>
<body>
@include('print._toolbar')
<div class="page {{ $format === 'a4' ? 'a4' : '' }}">
    <div class="center">
        <h1>{{ $business['name'] }}</h1>
        <div>{{ $branch->name }}</div>
        @if ($gstin)<div class="small">GSTIN: {{ $gstin }}</div>@endif
        <div class="bold" style="margin-top:4px">CREDIT NOTE</div>
    </div>
    <hr>
    <table class="small" style="border:0">
        <tr><td>No: <b>{{ $return->credit_note_no }}</b></td><td class="right">{{ $return->returned_at->format('d/m/Y h:i A') }}</td></tr>
        <tr><td colspan="2">Against invoice: {{ $return->sale->invoice_no }} ({{ $return->sale->sold_at->format('d/m/Y') }})</td></tr>
        <tr><td colspan="2">Customer: {{ $return->sale->customer_name }}{{ $return->sale->customer_gstin ? ' · GSTIN '.$return->sale->customer_gstin : '' }}</td></tr>
    </table>
    <hr>
    <table>
        <tr class="bold"><td>Item</td><td class="right">Qty</td><td class="right">Taxable</td><td class="right">Amount</td></tr>
        @foreach ($return->items as $item)
            <tr>
                <td>{{ $item->saleItem->product_name }}{{ $item->saleItem->variant_name ? ' ('.$item->saleItem->variant_name.')' : '' }}
                    @unless ($item->restock)<span class="small muted"> · damaged</span>@endunless</td>
                <td class="right">{{ $qty($item->quantity) }}</td>
                <td class="right">{{ $money($item->taxable_value) }}</td>
                <td class="right">{{ $money($item->line_total) }}</td>
            </tr>
        @endforeach
    </table>
    <hr>
    <table class="totals">
        <tr class="small"><td>Taxable value</td><td class="right">{{ $money($return->taxable_total) }}</td></tr>
        @if ((float) $return->igst_total > 0)<tr class="small"><td>IGST</td><td class="right">{{ $money($return->igst_total) }}</td></tr>
        @else<tr class="small"><td>CGST</td><td class="right">{{ $money($return->cgst_total) }}</td></tr><tr class="small"><td>SGST</td><td class="right">{{ $money($return->sgst_total) }}</td></tr>@endif
        @if ((float) $return->round_off != 0)<tr class="small"><td>Round off</td><td class="right">{{ $money($return->round_off) }}</td></tr>@endif
        <tr class="grand"><td>TOTAL</td><td class="right">₹{{ $money($return->total) }}</td></tr>
    </table>
    <hr>
    <div class="center bold">{{ $refund }}</div>
    <div class="center small">{{ $words }}</div>
    @if ($return->refund_method === 'exchange')
        <div class="center small" style="margin-top:4px">Show this credit note number at the counter: <b>{{ $return->credit_note_no }}</b></div>
    @endif
    @if ($return->reason)<div class="small" style="margin-top:4px">Reason: {{ $return->reason }}</div>@endif
    <div class="small muted" style="margin-top:4px">Processed by {{ $return->user->name }}</div>
</div>
</body>
</html>
