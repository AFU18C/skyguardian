@props(['type' => 'error'])

<div {{ $attributes->class(['alert']) }} role="alert">
    <span aria-hidden="true">!</span>
    <div>{{ $slot }}</div>
</div>
