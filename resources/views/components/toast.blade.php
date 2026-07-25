@php
    $toast = session('toast');
    if (! $toast && $errors->any()) {
        $toast = [
            'type' => 'error',
            'title' => 'Проверьте форму',
            'message' => 'Некоторые поля заполнены неправильно.',
        ];
    }
@endphp

@if ($toast)
    <div class="sg-toast sg-toast-{{ $toast['type'] ?? 'info' }}" data-toast role="status">
        <div class="sg-toast-icon">
            {{ match ($toast['type'] ?? 'info') {
                'success' => '✓',
                'error' => '!',
                'warning' => '▲',
                default => 'i',
            } }}
        </div>
        <div class="sg-toast-content">
            <strong>{{ $toast['title'] ?? 'Уведомление' }}</strong>
            <p>{{ $toast['message'] ?? '' }}</p>
        </div>
        <button type="button" data-toast-close aria-label="Закрыть">×</button>
    </div>
@endif
