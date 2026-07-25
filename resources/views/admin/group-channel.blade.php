<x-layouts.admin title="Группа-Канал">
    <x-slot:actions>
        <button class="sg-button sg-button-primary" type="button" data-modal-open="group-channel-create">+ Добавить Группу-Канал</button>
    </x-slot:actions>

    @if ($bots->isEmpty())
        <section class="sg-empty-state sg-empty-state-compact">
            <div class="sg-empty-symbol">➤</div>
            <h2>Боты групп и каналов ещё не добавлены</h2>
            <button class="sg-button sg-button-primary" type="button" data-modal-open="group-channel-create">Добавить</button>
        </section>
    @else
        <div class="sg-card-grid">
            @foreach ($bots as $bot)
                @php $detailsId = 'group-channel-details-'.$bot->id; @endphp
                <article class="sg-record-card sg-collapsible-card" data-collapsible-card>
                    <div class="sg-record-card-top">
                        <div class="sg-record-icon">BOT</div>
                        <div class="sg-record-title">
                            <h2>{{ $bot->bot_name }}</h2>
                            <p>{{ $bot->group_name }}</p>
                        </div>
                        <div class="sg-card-summary-actions">
                            <span class="sg-status {{ $bot->status === 'connected' ? 'sg-status-success' : ($bot->status === 'error' ? 'sg-status-error' : 'sg-status-muted') }}">
                                <span class="sg-status-dot"></span>{{ match($bot->status) {'connected' => 'Подключён', 'limited' => 'Ограничен', 'error' => 'Ошибка', default => 'Не проверен'} }}
                            </span>
                            <button class="sg-card-toggle" type="button" aria-expanded="false" aria-controls="{{ $detailsId }}" data-card-toggle><span aria-hidden="true"></span></button>
                        </div>
                    </div>
                    <div class="sg-card-details" id="{{ $detailsId }}" data-card-details hidden>
                        <dl class="sg-record-data">
                            <div><dt>Тип</dt><dd>{{ $bot->chat_type === 'channel' ? 'Канал' : 'Группа' }}</dd></div>
                            <div><dt>ID администратора</dt><dd>{{ $bot->admin_id }}</dd></div>
                            <div><dt>Ссылка</dt><dd><a href="{{ $bot->group_link }}" target="_blank" rel="noopener">{{ $bot->group_link }}</a></dd></div>
                            <div><dt>Chat ID</dt><dd>{{ $bot->chat_id ?: 'Не определён' }}</dd></div>
                            <div><dt>Username бота</dt><dd>{{ $bot->bot_username ? '@'.$bot->bot_username : 'Не определён' }}</dd></div>
                            <div><dt>Последняя проверка</dt><dd>{{ $bot->last_manual_check_at?->timezone('Europe/Kyiv')->format('d.m.Y H:i') ?? 'Не выполнялась' }}</dd></div>
                        </dl>
                        @if($bot->last_error)<div class="sg-inline-error">{{ $bot->last_error }}</div>@endif
                        <div class="sg-record-actions">
                            <form method="POST" action="{{ route('admin.group-channel.check', $bot) }}">@csrf<button class="sg-button sg-button-secondary sg-button-small" type="submit" data-submit-button>Проверить подключение</button></form>
                            <button class="sg-button sg-button-primary sg-button-small" type="button" data-modal-open="group-channel-edit-{{ $bot->id }}">Настроить</button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="sg-pagination">{{ $bots->links() }}</div>
    @endif

    @push('modals')
        <x-modal id="group-channel-create" title="Добавить Группу-Канал" size="lg">
            @include('admin.group-channel-form', ['bot' => null, 'action' => route('admin.group-channel.store')])
        </x-modal>
        @foreach($bots as $bot)
            <x-modal id="group-channel-edit-{{ $bot->id }}" title="Настройка Группа-Канал" size="lg">
                @include('admin.group-channel-form', ['bot' => $bot, 'action' => route('admin.group-channel.update', $bot)])
                <div class="sg-danger-zone">
                    <div><strong>Удаление</strong><p>Бот и привязка к группе/каналу будут удалены.</p></div>
                    <form method="POST" action="{{ route('admin.group-channel.destroy', $bot) }}" data-confirm="Удалить эту запись?">@csrf @method('DELETE')<button class="sg-button sg-button-danger" type="submit">Удалить</button></form>
                </div>
            </x-modal>
        @endforeach
        @if(session('group_channel_check'))
            @php $check = session('group_channel_check'); @endphp
            <x-modal id="group-channel-check-result" title="Результат проверки">
                @if($check['error'] ?? null)
                    <div class="sg-inline-error">{{ $check['error'] }}</div>
                @else
                    <dl class="sg-record-data">
                        <div><dt>Бот</dt><dd>{{ $check['bot'] }}</dd></div>
                        <div><dt>Группа/канал</dt><dd>{{ $check['chat'] }}</dd></div>
                        <div><dt>Chat ID</dt><dd>{{ $check['chat_id'] }}</dd></div>
                        <div><dt>Username</dt><dd>{{ $check['username'] ? '@'.$check['username'] : 'Нет' }}</dd></div>
                    </dl>
                    @foreach([
                        'is_administrator' => 'Бот является администратором',
                        'send_messages' => 'Отправка сообщений',
                        'delete_messages' => 'Удаление сообщений',
                        'pin_messages' => 'Закрепление сообщений',
                        'restrict_members' => 'Блокировка пользователей',
                        'invite_users' => 'Управление приглашениями',
                    ] as $key => $label)
                        <div class="sg-switch-row"><strong>{{ $label }}</strong><span class="sg-status {{ data_get($check, 'permissions.'.$key) ? 'sg-status-success' : 'sg-status-error' }}"><span class="sg-status-dot"></span>{{ data_get($check, 'permissions.'.$key) ? 'Разрешено' : 'Нет права' }}</span></div>
                    @endforeach
                @endif
            </x-modal>
            <div data-open-modal-on-load="group-channel-check-result"></div>
        @endif
    @endpush

    @if($errors->any())<div data-open-modal-on-load="group-channel-create"></div>@endif
</x-layouts.admin>
