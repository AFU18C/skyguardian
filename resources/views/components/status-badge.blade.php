@props(['status'])

@php
    [$label, $tone] = match ($status) {
        'connected', 'available' => ['Подключён', 'success'],
        'awaiting_code' => ['Ожидает код', 'warning'],
        'awaiting_password' => ['Требуется пароль', 'warning'],
        'awaiting_qr' => ['Ожидает QR', 'warning'],
        'qr_expired' => ['QR истёк', 'error'],
        'error', 'unavailable' => ['Ошибка', 'error'],
        'account_missing' => ['Нет аккаунта', 'error'],
        'disabled' => ['Отключён', 'muted'],
        default => ['Не проверен', 'neutral'],
    };
@endphp

<span {{ $attributes->class(['sg-status', 'sg-status-'.$tone]) }}>
    <span class="sg-status-dot"></span>
    {{ $label }}
</span>
