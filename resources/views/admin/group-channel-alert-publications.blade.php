@php
    $module = \App\Models\GroupChannelBot::MODULE_ALERT_PUBLICATIONS;
    $allUkraine = (bool) $bot->moduleSetting($module, 'all_ukraine', true);
    $selectedRegions = array_map('strval', (array) $bot->moduleSetting($module, 'region_uids', array_keys(\App\Models\GroupChannelBot::ALERT_REGIONS)));
    $selectedTypes = array_map('strval', (array) $bot->moduleSetting($module, 'alert_types', array_keys(\App\Models\GroupChannelBot::ALERT_TYPES)));
    $formatAlertsTimestamp = static function (string $attribute) use ($bot): ?string {
        $value = $bot->getRawOriginal($attribute);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return \Carbon\CarbonImmutable::parse($value)->format('d.m.Y H:i:s');
    };
@endphp

@if(!$bot->alerts_api_token)
    <div class="sg-inline-error">Токен alerts.in.ua не добавлен. Откройте «Редактировать» и сохраните токен API.</div>
@endif
@if($bot->alerts_api_last_error)
    <div class="sg-inline-error">{{ $bot->alerts_api_last_error }}</div>
@endif

<dl class="sg-record-data">
    <div><dt>API-токен</dt><dd>{{ $bot->alerts_api_token ? 'Добавлен' : 'Не добавлен' }}</dd></div>
    <div><dt>Последняя проверка</dt><dd>{{ $formatAlertsTimestamp('alerts_api_last_checked_at') ?? 'Не выполнялась' }}</dd></div>
    <div><dt>Последний успешный ответ</dt><dd>{{ $formatAlertsTimestamp('alerts_api_last_success_at') ?? 'Не получен' }}</dd></div>
    <div><dt>Синхронизация</dt><dd>{{ $bot->alerts_api_initialized_at ? 'Выполнена' : 'Ожидает первого запуска' }}</dd></div>
</dl>

<form method="POST" action="{{ route('admin.group-channel.alerts-api-check', $bot) }}">
    @csrf
    <button class="sg-button sg-button-secondary" type="submit" data-submit-button>Проверить API и публикацию</button>
</form>

<hr>
<form method="POST" action="{{ route('admin.group-channel.alert-settings.update', $bot) }}">
    @csrf @method('PUT')

    <fieldset data-alert-region-settings>
        <legend>Территория публикаций</legend>
        <label class="sg-switch-row">
            <span><strong>Вся Украина</strong><small>Публиковать события по всем областям, районам, городам и громадам.</small></span>
            <input type="hidden" name="all_ukraine" value="0">
            <input type="checkbox" name="all_ukraine" value="1" data-alert-all-ukraine @checked($allUkraine)>
        </label>
        <p>Локация выводится иерархически: область — район/город/громада. При выборе отдельной области учитываются все её локации.</p>
        <div class="sg-form-grid" data-alert-regions @if($allUkraine) hidden @endif>
            @foreach(\App\Models\GroupChannelBot::ALERT_REGIONS as $uid => $region)
                <label class="sg-switch-row">
                    <span><strong>{{ $region }}</strong></span>
                    <input
                        type="checkbox"
                        name="region_uids[]"
                        value="{{ $uid }}"
                        @checked(in_array((string) $uid, $selectedRegions, true))
                    >
                </label>
            @endforeach
        </div>
        @error('region_uids')<small class="sg-field-error">{{ $message }}</small>@enderror
    </fieldset>

    <fieldset>
        <legend>Типы угроз</legend>
        <div class="sg-form-grid">
            @foreach(\App\Models\GroupChannelBot::ALERT_TYPES as $type => $label)
                <label class="sg-switch-row">
                    <span><strong>{{ $label }}</strong></span>
                    <input
                        type="checkbox"
                        name="alert_types[]"
                        value="{{ $type }}"
                        @checked(in_array($type, $selectedTypes, true))
                    >
                </label>
            @endforeach
        </div>
        @error('alert_types')<small class="sg-field-error">{{ $message }}</small>@enderror
    </fieldset>

    <fieldset>
        <legend>События</legend>
        <label class="sg-switch-row">
            <span><strong>Публиковать начало тревоги</strong></span>
            <input type="hidden" name="publish_start" value="0">
            <input type="checkbox" name="publish_start" value="1" @checked($bot->moduleSetting($module, 'publish_start', true))>
        </label>
        <label class="sg-switch-row">
            <span><strong>Публиковать отбой</strong></span>
            <input type="hidden" name="publish_end" value="0">
            <input type="checkbox" name="publish_end" value="1" @checked($bot->moduleSetting($module, 'publish_end', true))>
        </label>
        <label class="sg-switch-row">
            <span><strong>Тихая отправка</strong><small>Telegram не будет отправлять звуковое уведомление.</small></span>
            <input type="hidden" name="disable_notification" value="0">
            <input type="checkbox" name="disable_notification" value="1" @checked($bot->moduleSetting($module, 'disable_notification', false))>
        </label>
    </fieldset>

    <fieldset>
        <legend>Шаблоны сообщений</legend>
        <p>Переменные: <code>{region}</code>, <code>{time}</code>, <code>{threat_type}</code>, <code>{details}</code>. Цель или направление из поля API <code>notes</code> добавляется автоматически, когда оно заполнено.</p>
        <div class="sg-field">
            <label for="alert-start-template-{{ $bot->id }}">Сообщение о тревоге</label>
            <textarea id="alert-start-template-{{ $bot->id }}" name="start_template" rows="7" maxlength="3500" required>{{ $bot->moduleSetting($module, 'start_template', \App\Models\GroupChannelBot::DEFAULT_ALERT_START_TEMPLATE) }}</textarea>
            @error('start_template')<small class="sg-field-error">{{ $message }}</small>@enderror
        </div>
        <div class="sg-field">
            <label for="alert-end-template-{{ $bot->id }}">Сообщение об отбое</label>
            <textarea id="alert-end-template-{{ $bot->id }}" name="end_template" rows="6" maxlength="3500" required>{{ $bot->moduleSetting($module, 'end_template', \App\Models\GroupChannelBot::DEFAULT_ALERT_END_TEMPLATE) }}</textarea>
            @error('end_template')<small class="sg-field-error">{{ $message }}</small>@enderror
        </div>
    </fieldset>

    <div class="sg-record-actions">
        <button class="sg-button sg-button-primary" type="submit" data-submit-button>Сохранить настройки тревог</button>
    </div>
</form>
