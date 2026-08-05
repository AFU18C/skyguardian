@php
    $mapButtonEnabled = $useOld
        ? (bool) old('map_button_enabled', false)
        : ($isEdit ? (bool) $storedRules->get('map_button_url')?->is_active : false);
    $mapButtonUrl = (string) $setting('map_button_url', 'https://skyguardian.pp.ua/');
@endphp

<section class="sg-rules">
    <div class="sg-section-heading">
        <div>
            <h3>Кнопка карты тревог</h3>
            <p>Добавляет отдельную кнопку под каждой новой публикацией этого источника.</p>
        </div>
    </div>

    <label class="sg-switch-row">
        <span>
            <strong>Показывать кнопку «Мапа тривог України»</strong>
            <small>Настройка действует отдельно для каждого источника новостей или воздушных тревог.</small>
        </span>
        <input type="hidden" name="map_button_enabled" value="0">
        <input type="checkbox" name="map_button_enabled" value="1" @checked($mapButtonEnabled)>
    </label>

    <label class="sg-field">
        <span>Ссылка кнопки</span>
        <input type="url" name="map_button_url" maxlength="2048"
               value="{{ $mapButtonUrl }}"
               placeholder="https://skyguardian.pp.ua/">
        <small>Разрешены ссылки HTTP/HTTPS. Для настоящей Telegram-кнопки Bot API канала должен иметь право редактировать сообщения.</small>
        @if ($useOld) @error('map_button_url')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
    </label>

    @if ($useOld)
        @error('map_button_enabled')<p class="sg-field-error">{{ $message }}</p>@enderror
    @endif
</section>
