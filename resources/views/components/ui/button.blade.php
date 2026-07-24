@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'default',
])

@php
    $classes = \Illuminate\Support\Arr::toCssClasses([
        'button',
        'button-primary' => $variant === 'primary',
        'button-ghost' => $variant === 'ghost',
        'button-danger' => $variant === 'danger',
    ]);
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
