<dl class="sg-record-data">
    <div><dt>Бот</dt><dd>{{ $bot->bot_name }}{{ $bot->bot_username ? ' (@'.$bot->bot_username.')' : '' }}</dd></div>
    <div><dt>Тип</dt><dd>{{ $bot->chat_type === 'channel' ? 'Канал' : 'Группа' }}</dd></div>
    <div><dt>ID администратора</dt><dd>{{ $bot->admin_id }}</dd></div>
    <div><dt>Ссылка</dt><dd><a href="{{ $bot->group_link }}" target="_blank" rel="noopener">{{ $bot->group_link }}</a></dd></div>
    <div><dt>Chat ID</dt><dd>{{ $bot->chat_id ?: 'Не определён' }}</dd></div>
    <div><dt>Последняя проверка</dt><dd>{{ $bot->last_manual_check_at?->timezone('Europe/Kyiv')->format('d.m.Y H:i') ?? 'Не выполнялась' }}</dd></div>
    <div><dt>Последнее событие</dt><dd>{{ $bot->last_update_at?->timezone('Europe/Kyiv')->format('d.m.Y H:i') ?? 'Не получено' }}</dd></div>
    <div><dt>Webhook</dt><dd>{{ $bot->webhook_registered_at ? 'Подключён' : 'Не подключён' }}</dd></div>
</dl>

@if($bot->last_error)<div class="sg-inline-error">{{ $bot->last_error }}</div>@endif
@if($bot->last_test_message_error)<div class="sg-inline-error">{{ $bot->last_test_message_error }}</div>@endif
@if($bot->webhook_last_error)<div class="sg-inline-error">{{ $bot->webhook_last_error }}</div>@endif

<div class="sg-record-actions">
    <form method="POST" action="{{ route('admin.group-channel.check', $bot) }}">@csrf<button class="sg-button sg-button-secondary" type="submit" data-submit-button>Проверить подключение</button></form>
    <form method="POST" action="{{ route('admin.group-channel.test-message', $bot) }}">@csrf<button class="sg-button sg-button-secondary" type="submit" data-submit-button>Отправить тестовое сообщение</button></form>
    <form method="POST" action="{{ route('admin.group-channel.webhook.register', $bot) }}">@csrf<button class="sg-button sg-button-secondary" type="submit" data-submit-button>Подключить webhook</button></form>
</div>

<hr>
<section class="sg-functions-section">
    <h3>Функции этого чата</h3>
    <p>Отметьте нужные функции. После сохранения у включённых функций появится стрелка для раскрытия настроек.</p>

    @php
        $configurableModules = [
            'publications',
            'bulk_delete',
            'antispam',
            'welcome',
            'subscription_check',
            'join_requests',
            'human_verification',
            'warnings',
            'newcomer_restrictions',
            'slow_mode',
        ];
        $moduleFormId = 'group-channel-modules-'.$bot->id;
        $openModule = (string) session('open_group_channel_module');
    @endphp

    <form id="{{ $moduleFormId }}" method="POST" action="{{ route('admin.group-channel.modules.update', $bot) }}">
        @csrf
        @method('PUT')
    </form>

    <div class="sg-module-list">
        @foreach($availableModules as $module => $label)
            @php
                $enabled = $bot->moduleEnabled($module);
                $configurable = in_array($module, $configurableModules, true);
                $expanded = $enabled && $openModule === $module;
            @endphp
            <section
                id="group-channel-module-{{ $module }}-{{ $bot->id }}"
                class="sg-module-card {{ $expanded ? 'is-open' : '' }}"
                data-module-card
            >
                <div class="sg-module-head">
                    <label class="sg-module-switch">
                        <strong>{{ $label }}</strong>
                        <input
                            type="checkbox"
                            name="modules[]"
                            value="{{ $module }}"
                            form="{{ $moduleFormId }}"
                            data-module-checkbox
                            @checked($enabled)
                        >
                    </label>
                    @if($configurable)
                        <button
                            class="sg-module-toggle"
                            type="button"
                            data-module-toggle
                            aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                            title="Открыть настройки"
                            @if(!$enabled) hidden @endif
                        >⌄</button>
                    @endif
                </div>

                @if($configurable)
                    <div class="sg-module-panel" data-module-panel @if(!$expanded) hidden @endif>
                        @include('admin.group-channel-module-content', ['bot' => $bot, 'module' => $module])
                    </div>
                @endif
            </section>
        @endforeach
    </div>

    <div class="sg-record-actions sg-module-save-row">
        <button class="sg-button sg-button-primary" type="submit" form="{{ $moduleFormId }}" data-submit-button>Сохранить функции</button>
    </div>
</section>

<div class="sg-danger-zone">
    <div><strong>Удаление</strong><p>Бот и привязка к группе/каналу будут удалены.</p></div>
    <form method="POST" action="{{ route('admin.group-channel.destroy', $bot) }}" data-confirm="Удалить эту запись?">@csrf @method('DELETE')<button class="sg-button sg-button-danger" type="submit">Удалить</button></form>
</div>
