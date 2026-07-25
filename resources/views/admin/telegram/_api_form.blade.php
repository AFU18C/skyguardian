@php
    $isEdit = isset($telegramApi) && $telegramApi;
    $context = $isEdit ? 'api-'.$telegramApi->id : 'api-create';
    $useOld = old('form_context') === $context;
@endphp

<form method="POST" action="{{ $action }}" class="sg-form" data-dirty-form>
    @csrf
    @if ($isEdit) @method('PUT') @endif
    <input type="hidden" name="form_context" value="{{ $context }}">

    <div class="sg-form-grid">
        <label class="sg-field sg-field-wide">
            <span>Название конфигурации <b>*</b></span>
            <input type="text" name="name" maxlength="255" required
                   value="{{ $useOld ? old('name') : ($telegramApi->name ?? '') }}"
                   placeholder="Основной Telegram API">
            @if ($useOld) @error('name')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>

        <label class="sg-field">
            <span>API ID {{ $isEdit ? '' : '*' }}</span>
            <input type="number" name="api_id" min="1" @required(! $isEdit)
                   value="{{ $useOld ? old('api_id') : '' }}"
                   placeholder="{{ $isEdit ? 'Оставьте пустым, чтобы не изменять' : 'Введите API ID' }}">
            @if ($isEdit)<small>Текущее значение скрыто частично и не передаётся в форму.</small>@endif
            @if ($useOld) @error('api_id')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>

        <label class="sg-field">
            <span>API Hash {{ $isEdit ? '' : '*' }}</span>
            <input type="password" name="api_hash" minlength="16" maxlength="255" @required(! $isEdit)
                   placeholder="{{ $isEdit ? 'Оставьте пустым, чтобы не изменять' : 'Введите API Hash' }}" autocomplete="new-password">
            <small>Полное значение после сохранения не отображается.</small>
            @if ($useOld) @error('api_hash')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>
    </div>

    <label class="sg-switch-row">
        <span><strong>Конфигурация активна</strong><small>Неактивную конфигурацию нельзя использовать для новых подключений.</small></span>
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked((bool) ($useOld ? old('is_active') : ($telegramApi->is_active ?? true)))>
    </label>

    <div class="sg-form-actions">
        <button type="button" class="sg-button sg-button-secondary" data-modal-close>Отмена</button>
        <button type="submit" class="sg-button sg-button-primary" data-submit-button>Сохранить</button>
    </div>
</form>
