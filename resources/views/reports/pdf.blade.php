<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14mm 10mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #111; }
        h1 { font-size: 13pt; margin: 0; }
        .meta { color: #555; margin: 2px 0 10px; }
        h2 { font-size: 10pt; margin: 14px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f1f4; text-align: left; font-weight: bold; }
        th, td { border: 0.5pt solid #ccc; padding: 3px 4px; vertical-align: top; }
        .r { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; background: #fafafa; }
        .note { color: #666; font-size: 7pt; margin-top: 3px; }
        .empty { color: #888; text-align: center; padding: 8px; }
        .foot { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7pt; color: #888; }
    </style>
</head>
<body>
    <div class="foot">Printed {{ now()->format('d M Y, h:i A') }} · amounts in Rs.</div>
    @php($logo = \App\Support\Branding::logoDataUri())
    @if ($logo)<img src="{{ $logo }}" alt="" style="float: right; max-height: 14mm; max-width: 40mm">@endif
    <h1>{{ $header[1] ?? '' }}</h1>
    <div class="meta">{{ $header[0] ?? '' }} · {{ implode(' · ', array_slice($header, 2)) }}</div>

    @foreach ($sections as $section)
        @php($totals = $section->totals())
        @if (count($sections) > 1)<h2>{{ $section->title }}</h2>@endif
        <table>
            <thead>
                <tr>
                    @foreach ($section->columns as $col)
                        <th class="{{ in_array($col['type'], ['money', 'qty', 'int', 'percent']) ? 'r' : '' }}">{{ $col['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($section->rows as $row)
                    <tr @if (in_array($row['kind'] ?? null, ['total', 'head', 'subtotal'])) style="font-weight: bold; background: #fafafa" @endif>
                        @foreach ($section->columns as $col)
                            <td class="{{ in_array($col['type'], ['money', 'qty', 'int', 'percent']) ? 'r' : '' }}">{{ $fmt($col, $row[$col['key']] ?? null) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td class="empty" colspan="{{ count($section->columns) }}">Nothing for this period.</td></tr>
                @endforelse
            </tbody>
            @if ($totals && count($section->rows))
                <tfoot>
                    <tr>
                        @foreach ($section->columns as $i => $col)
                            <td class="{{ in_array($col['type'], ['money', 'qty', 'int', 'percent']) ? 'r' : '' }}">{{ array_key_exists($col['key'], $totals) ? $fmt($col, $totals[$col['key']]) : ($i === 0 ? 'Total' : '') }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
        @if ($section->note)<div class="note">{{ $section->note }}</div>@endif
    @endforeach
</body>
</html>
