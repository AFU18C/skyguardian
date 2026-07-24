@props(['href' => null])

<a href="{{ $href ?? route('home') }}" {{ $attributes->class(['brand-lockup']) }}>
    <span class="brand-mark">{{ config('branding.short_name') }}</span>
    <span class="brand-name">{{ config('branding.name') }}</span>
</a>
