<div class="no-print">
    @foreach (['80mm' => '80 mm', '58mm' => '58 mm', 'a4' => 'A4 invoice'] as $f => $label)
        <a href="{{ request()->fullUrlWithQuery(['format' => $f, 'autoprint' => 0]) }}"><button type="button" @disabled($format === $f)>{{ $label }}</button></a>
    @endforeach
    <button type="button" onclick="window.print()">Print</button>
</div>
