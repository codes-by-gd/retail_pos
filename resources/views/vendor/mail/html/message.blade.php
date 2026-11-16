<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
@php($logo = \App\Support\Branding::logoUrl())
@if ($logo)
<img src="{{ $logo }}" alt="{{ settings('business.name') }}" style="max-height: 56px; max-width: 220px;">
@else
{{ settings('business.name') ?: config('app.name') }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ settings('business.legal_name') ?: settings('business.name') }}
@if (settings('business.city') || settings('business.phone'))
<br>{{ collect([settings('business.address'), settings('business.city'), settings('business.phone')])->filter()->implode(' · ') }}
@endif
<br>This is an automatic message from the shop's billing system.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
