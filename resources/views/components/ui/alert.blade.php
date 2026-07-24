@props(['type' => 'error'])

<div {{ $attributes->class(['alert', 'alert-success' => $type === 'success']) }} role="alert">
    <span aria-hidden="true">{{ $type === 'success' ? '✓' : '!' }}</span>
    <div>{{ $slot }}</div>
</div>
