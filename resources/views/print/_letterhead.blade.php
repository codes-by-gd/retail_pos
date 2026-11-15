{{-- $title, $number, $date, optional $extra --}}
<table style="border:0">
    <tr>
        <td style="border:0; width:60%">
            <h1>{{ $business['legal_name'] ?: $business['name'] }}</h1>
            <div>{{ $branch->name }}</div>
            <div class="small">{{ collect([$branch->address ?: $business['address'], $branch->city ?: $business['city'], $branch->pincode ?: $business['pincode']])->filter()->implode(', ') }}</div>
            @if ($branch->phone || $business['phone'])<div class="small">Ph: {{ $branch->phone ?: $business['phone'] }}</div>@endif
            @if ($gstin)<div class="bold">GSTIN: {{ $gstin }}</div>@endif
        </td>
        <td style="border:0" class="right">
            <div class="bold" style="font-size:16px">{{ $title }}</div>
            <div>No: <b>{{ $number }}</b></div>
            <div>Date: {{ $date }}</div>
            {!! $extra ?? '' !!}
        </td>
    </tr>
</table>
