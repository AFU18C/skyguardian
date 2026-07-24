@props(['label' => 'Загрузка данных…'])

<div {{ $attributes->class(['loading-state']) }} role="status">
    <span class="spinner" aria-hidden="true"></span>
    <span>{{ $label }}</span>
</div>
