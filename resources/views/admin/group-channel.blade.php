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
        <div class="sg-group-channel-grid">
            @foreach ($bots as $bot)
                <article class="sg-group-channel-card">
                    <div class="sg-group-channel-mark" aria-hidden="true">▲</div>
                    <div class="sg-group-channel-title">
                        <h2>{{ $bot->group_name }}</h2>
                        <a href="{{ $bot->group_link }}" target="_blank" rel="noopener">
                            {{ $bot->bot_username ? '@'.$bot->bot_username : $bot->bot_name }}
                        </a>
                    </div>
                    <div class="sg-group-channel-actions">
                        <button class="sg-group-channel-action" type="button" title="Редактировать" aria-label="Редактировать" data-modal-open="group-channel-edit-{{ $bot->id }}">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4Z"></path><path d="m13.5 6.5 4 4"></path></svg>
                        </button>
                        <button class="sg-group-channel-action" type="button" title="Управление" aria-label="Управление" data-modal-open="group-channel-manage-{{ $bot->id }}">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1v.1h-4v-.1a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1-.4h-.1v-4H3A1.7 1.7 0 0 0 4.6 8.5a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1v-.1h4V3a1.7 1.7 0 0 0 1.1 1.6 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.38.32.6.79.6 1.3v3.4c0 .5-.22.98-.6 1.3Z"></path></svg>
                        </button>
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
            <x-modal id="group-channel-edit-{{ $bot->id }}" title="Редактировать Группа-Канал" size="lg">
                @include('admin.group-channel-form', ['bot' => $bot, 'action' => route('admin.group-channel.update', $bot)])
            </x-modal>
            <x-modal id="group-channel-manage-{{ $bot->id }}" title="Управление: {{ $bot->group_name }}" size="lg">
                <dl class="sg-record-data">
                    <div><dt>Бот</dt><dd>{{ $bot->bot_name }}</dd></div>
                    <div><dt>Тип</dt><dd>{{ $bot->chat_type === 'channel' ? 'Канал' : 'Группа' }}</dd></div>
                    <div><dt>ID администратора</dt><dd>{{ $bot->admin_id }}</dd></div>
                    <div><dt>Ссылка</dt><dd><a href="{{ $bot->group_link }}" target="_blank" rel="noopener">{{ $bot->group_link }}</a></dd></div>
                    <div><dt>Chat ID</dt><dd>{{ $bot->chat_id ?: 'Не определён' }}</dd></div>
                    <div><dt>Последняя проверка</dt><dd>{{ $bot->last_manual_check_at?->timezone('Europe/Kyiv')->format('d.m.Y H:i') ?? 'Не выполнялась' }}</dd></div>
                    <div><dt>Последнее тестовое сообщение</dt><dd>{{ $bot->last_test_message_at?->timezone('Europe/Kyiv')->format('d.m.Y H:i') ?? 'Не отправлялось' }}</dd></div>
                </dl>

                @if($bot->last_error)<div class="sg-inline-error">{{ $bot->last_error }}</div>@endif
                @if($bot->last_test_message_error)<div class="sg-inline-error">{{ $bot->last_test_message_error }}</div>@endif

                <div class="sg-record-actions">
                    <form method="POST" action="{{ route('admin.group-channel.check', $bot) }}">@csrf<button class="sg-button sg-button-secondary" type="submit" data-submit-button>Проверить подключение</button></form>
                    <form method="POST" action="{{ route('admin.group-channel.test-message', $bot) }}">@csrf<button class="sg-button sg-button-secondary" type="submit" data-submit-button>Отправить тестовое сообщение</button></form>
                </div>

                <h3>Функции этого чата</h3>
                <p>Все функции нового чата отключены. Включайте только нужные.</p>
                <form method="POST" action="{{ route('admin.group-channel.modules.update', $bot) }}">
                    @csrf
                    @method('PUT')
                    @foreach($availableModules as $key => $label)
                        <label class="sg-switch-row">
                            <strong>{{ $label }}</strong>
                            <input type="checkbox" name="modules[]" value="{{ $key }}" @checked($bot->moduleEnabled($key))>
                        </label>
                    @endforeach
                    <div class="sg-record-actions">
                        <button class="sg-button sg-button-primary" type="submit" data-submit-button>Сохранить функции</button>
                    </div>
                </form>

                @if($bot->moduleEnabled('publications'))
                    <hr>
                    <h3>Редактор публикации</h3>
                    <form method="POST" action="{{ route('admin.group-channel.publications.store', $bot) }}">
                        @csrf
                        <div class="sg-field">
                            <label for="publication-text-{{ $bot->id }}">Текст</label>
                            <textarea id="publication-text-{{ $bot->id }}" name="text" rows="8" maxlength="4096" required></textarea>
                        </div>
                        <div class="sg-field">
                            <label for="publication-date-{{ $bot->id }}">Дата и время отложенной отправки</label>
                            <input id="publication-date-{{ $bot->id }}" name="scheduled_at" type="datetime-local">
                        </div>
                        @if($bot->moduleEnabled('auto_delete_publications'))
                            <div class="sg-field">
                                <label for="publication-delete-after-{{ $bot->id }}">Удалить после отправки</label>
                                <div class="sg-record-actions">
                                    <input id="publication-delete-after-{{ $bot->id }}" name="delete_after_value" type="number" min="1" max="10080" placeholder="Количество">
                                    <select name="delete_after_unit">
                                        <option value="minutes">минут</option>
                                        <option value="hours">часов</option>
                                    </select>
                                </div>
                            </div>
                        @endif
                        <div class="sg-record-actions">
                            <button class="sg-button sg-button-secondary" type="submit" name="action" value="draft">Сохранить черновик</button>
                            <button class="sg-button sg-button-secondary" type="submit" name="action" value="schedule">Запланировать</button>
                            <button class="sg-button sg-button-primary" type="submit" name="action" value="send" data-submit-button>Отправить сейчас</button>
                        </div>
                    </form>
                    <p>Редактор поддерживает текст, черновики, отложенную отправку и автоматическое удаление.</p>
                @endif

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
                        'post_messages' => 'Публикация в канале',
                        'edit_messages' => 'Редактирование сообщений',
                        'delete_messages' => 'Удаление сообщений',
                        'pin_messages' => 'Закрепление сообщений',
                        'restrict_members' => 'Ограничение пользователей',
                        'invite_users' => 'Управление приглашениями',
                        'manage_chat' => 'Управление чатом',
                        'manage_topics' => 'Управление темами',
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
