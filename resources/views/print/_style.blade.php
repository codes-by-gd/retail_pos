@php($thermal = $format !== 'a4')
<style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: {{ $thermal ? "'Courier New', ui-monospace, monospace" : "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif" }}; color: #000; background: #fff; }
    .page { width: {{ $format === '58mm' ? '58mm' : ($format === '80mm' ? '80mm' : '210mm') }}; margin: 0 auto; padding: {{ $thermal ? '2mm 3mm' : '12mm 12mm' }}; font-size: {{ $format === '58mm' ? '10px' : ($format === '80mm' ? '11.5px' : '12px') }}; }
    .logo { display: block; max-height: {{ $thermal ? '14mm' : '18mm' }}; max-width: {{ $thermal ? '60%' : '45mm' }}; margin: {{ $thermal ? '0 auto 2px' : '0 0 4px' }}; {{ $thermal ? 'filter: grayscale(1);' : '' }} }
    h1 { font-size: {{ $thermal ? '15px' : '20px' }}; margin: 0; }
    .center { text-align: center; } .right { text-align: right; } .muted { color: #444; } .bold { font-weight: 700; }
    .small { font-size: 0.85em; }
    hr { border: 0; border-top: 1px dashed #000; margin: 4px 0; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: {{ $thermal ? '1px 0' : '4px 6px' }}; vertical-align: top; }
    th { text-align: left; }
    .a4 th, .a4 td { border: 1px solid #999; }
    .a4 thead th { background: #f2f2f2; font-size: 11px; }
    .totals td { padding: 1px 0; }
    .grand { font-size: {{ $thermal ? '15px' : '15px' }}; font-weight: 700; }
    .no-print { margin: 10px auto; text-align: center; font-family: system-ui, sans-serif; }
    .no-print button { padding: 6px 14px; margin: 0 4px; cursor: pointer; }
    @page { size: {{ $format === 'a4' ? 'A4' : ($format === '58mm' ? '58mm auto' : '80mm auto') }}; margin: {{ $thermal ? '0' : '8mm' }}; }
    @media print { .no-print { display: none; } .page { padding-top: 0; } }
</style>
@if ($autoprint)
    <script>window.addEventListener('load', () => { window.focus(); window.print(); });</script>
@endif
