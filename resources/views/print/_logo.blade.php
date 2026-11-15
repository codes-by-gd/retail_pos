@php($logo = \App\Support\Branding::logoDataUri())
@if ($logo)<img src="{{ $logo }}" alt="" class="logo">@endif
