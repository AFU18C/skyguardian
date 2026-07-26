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
<h3>Функции этого чата</h3>
<p>Включаются отдельно. Отключённые функции не обрабатывают события.</p>
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
    <form method="POST" action="{{ route('admin.group-channel.publications.store', $bot) }}" enctype="multipart/form-data">
        @csrf
        <div class="sg-form-grid">
            <div class="sg-field">
                <label for="publication-type-{{ $bot->id }}">Тип публикации</label>
                <select id="publication-type-{{ $bot->id }}" name="type" required>
                    <option value="text">Текст</option>
                    <option value="photo">Фото</option>
                    <option value="video">Видео</option>
                    <option value="album">Альбом</option>
                    <option value="document">Документ</option>
                    <option value="poll">Опрос / викторина</option>
                </select>
            </div>
            <div class="sg-field">
                <label for="publication-media-{{ $bot->id }}">Файлы</label>
                <input id="publication-media-{{ $bot->id }}" name="media[]" type="file" multiple>
                <small>Для альбома — 2–10 фото или видео.</small>
            </div>
        </div>
        <div class="sg-field">
            <label for="publication-text-{{ $bot->id }}">Текст или подпись</label>
            <textarea id="publication-text-{{ $bot->id }}" name="text" rows="7" maxlength="4096"></textarea>
        </div>
        <div class="sg-form-grid">
            <div class="sg-field">
                <label for="publication-buttons-{{ $bot->id }}">Кнопки</label>
                <textarea id="publication-buttons-{{ $bot->id }}" name="buttons_text" rows="4" placeholder="Название | https://example.com&#10;Проверить | callback:check"></textarea>
            </div>
            <div class="sg-field">
                <label for="publication-reactions-{{ $bot->id }}">Реакции после отправки</label>
                <input id="publication-reactions-{{ $bot->id }}" name="reactions_text" placeholder="👍, ❤️">
            </div>
        </div>

        @if($bot->moduleEnabled('polls'))
            <fieldset>
                <legend>Опрос или викторина</legend>
                <div class="sg-field"><label>Вопрос</label><input name="poll_question" maxlength="300"></div>
                <div class="sg-field"><label>Варианты ответа — каждый с новой строки</label><textarea name="poll_options" rows="5"></textarea></div>
                <div class="sg-form-grid">
                    <div class="sg-field"><label>Тип</label><select name="poll_type"><option value="regular">Опрос</option><option value="quiz">Викторина</option></select></div>
                    <div class="sg-field"><label>Номер правильного варианта, начиная с 0</label><input name="poll_correct_option_id" type="number" min="0" max="9" value="0"></div>
                    <div class="sg-field"><label>Время проведения, секунд</label><input name="poll_open_period" type="number" min="5" max="600"></div>
                    <label class="sg-switch-row"><strong>Анонимный опрос</strong><input name="poll_is_anonymous" type="checkbox" value="1" checked></label>
                </div>
            </fieldset>
        @endif

        <div class="sg-form-grid">
            <div class="sg-field">
                <label for="publication-date-{{ $bot->id }}">Дата и время отложенной отправки</label>
                <input id="publication-date-{{ $bot->id }}" name="scheduled_at" type="datetime-local">
            </div>
            @if($bot->moduleEnabled('auto_delete_publications'))
                <div class="sg-field">
                    <label>Удалить после отправки</label>
                    <div class="sg-record-actions">
                        <input name="delete_after_value" type="number" min="1" max="10080" placeholder="Количество">
                        <select name="delete_after_unit"><option value="minutes">Минут</option><option value="hours">Часов</option></select>
                    </div>
                </div>
            @endif
            <label class="sg-switch-row"><strong>Тихая отправка</strong><input name="disable_notification" type="checkbox" value="1"></label>
        </div>
        <div class="sg-record-actions">
            <button class="sg-button sg-button-secondary" type="submit" name="action" value="preview">Предпросмотр</button>
            @if($bot->moduleEnabled('drafts'))<button class="sg-button sg-button-secondary" type="submit" name="action" value="draft">Сохранить черновик</button>@endif
            @if($bot->moduleEnabled('scheduled_publications'))<button class="sg-button sg-button-secondary" type="submit" name="action" value="schedule">Запланировать</button>@endif
            <button class="sg-button sg-button-primary" type="submit" name="action" value="send" data-submit-button>Отправить сейчас</button>
        </div>
    </form>

    @if($bot->publications->isNotEmpty())
        <h3>Последние публикации</h3>
        @foreach($bot->publications as $publication)
            <div class="sg-switch-row">
                <div>
                    <strong>#{{ $publication->id }} · {{ $publication->type }} · {{ $publication->status }}</strong>
                    <p>{{ \Illuminate\Support\Str::limit($publication->text, 100) }}</p>
                    @if($publication->scheduled_at)<small>Отправка: {{ $publication->scheduled_at->timezone('Europe/Kyiv')->format('d.m.Y H:i') }}</small>@endif
                    @if($publication->last_error)<div class="sg-inline-error">{{ $publication->last_error }}</div>@endif
                </div>
                <div class="sg-record-actions">
                    @if(in_array($publication->status, ['draft', 'error'], true))
                        <form method="POST" action="{{ route('admin.group-channel.publications.send', [$bot, $publication]) }}">@csrf<button class="sg-button sg-button-secondary" type="submit">Отправить</button></form>
                    @endif
                    <form method="POST" action="{{ route('admin.group-channel.publications.destroy', [$bot, $publication]) }}" data-confirm="Удалить публикацию из SkyGuardian?">@csrf @method('DELETE')<button class="sg-button sg-button-danger" type="submit">Удалить</button></form>
                </div>
            </div>
        @endforeach
    @endif
@endif

@if(collect(['antispam','welcome','subscription_check','join_requests','human_verification','warnings','newcomer_restrictions','slow_mode'])->contains(fn($module) => $bot->moduleEnabled($module)))
    <hr>
    <h3>Настройки модерации</h3>
    <form method="POST" action="{{ route('admin.group-channel.module-settings.update', $bot) }}">
        @csrf
        @method('PUT')

        @if($bot->moduleEnabled('antispam'))
            <fieldset><legend>Антиспам</legend>
                <label class="sg-switch-row"><strong>Удалять ссылки</strong><input type="checkbox" name="settings[antispam][delete_links]" value="1" @checked($bot->moduleSetting('antispam','delete_links'))></label>
                <label class="sg-switch-row"><strong>Удалять сообщения новых участников</strong><input type="checkbox" name="settings[antispam][delete_new_member_messages]" value="1" @checked($bot->moduleSetting('antispam','delete_new_member_messages'))></label>
                <label class="sg-switch-row"><strong>Блокировать повторяющийся текст</strong><input type="checkbox" name="settings[antispam][block_duplicates]" value="1" @checked($bot->moduleSetting('antispam','block_duplicates'))></label>
                <label class="sg-switch-row"><strong>Удалять короткие сообщения</strong><input type="checkbox" name="settings[antispam][delete_short_messages]" value="1" @checked($bot->moduleSetting('antispam','delete_short_messages'))></label>
                <label class="sg-switch-row"><strong>Подозрительные символы</strong><input type="checkbox" name="settings[antispam][suspicious_symbols]" value="1" @checked($bot->moduleSetting('antispam','suspicious_symbols'))></label>
                <div class="sg-form-grid">
                    <div class="sg-field"><label>Минут после вступления</label><input name="settings[antispam][new_member_minutes]" type="number" value="{{ $bot->moduleSetting('antispam','new_member_minutes',10) }}"></div>
                    <div class="sg-field"><label>Лимит сообщений</label><input name="settings[antispam][message_limit]" type="number" value="{{ $bot->moduleSetting('antispam','message_limit',0) }}"></div>
                    <div class="sg-field"><label>Период лимита, секунд</label><input name="settings[antispam][message_limit_period_seconds]" type="number" value="{{ $bot->moduleSetting('antispam','message_limit_period_seconds',60) }}"></div>
                    <div class="sg-field"><label>Максимум упоминаний</label><input name="settings[antispam][max_mentions]" type="number" value="{{ $bot->moduleSetting('antispam','max_mentions',0) }}"></div>
                    <div class="sg-field"><label>Минимальная длина</label><input name="settings[antispam][min_length]" type="number" value="{{ $bot->moduleSetting('antispam','min_length',2) }}"></div>
                </div>
                <div class="sg-field"><label>Запрещённые слова — по одному с новой строки</label><textarea name="settings[antispam][forbidden_words_text]" rows="5">{{ implode("\n", $bot->moduleSetting('antispam','forbidden_words',[])) }}</textarea></div>
            </fieldset>
        @endif

        @if($bot->moduleEnabled('welcome'))
            <fieldset><legend>Приветствие</legend>
                <div class="sg-field"><label>Текст</label><textarea name="settings[welcome][text]" rows="5">{{ $bot->moduleSetting('welcome','text','') }}</textarea></div>
                <div class="sg-field"><label>Правила</label><textarea name="settings[welcome][rules]" rows="4">{{ $bot->moduleSetting('welcome','rules','') }}</textarea></div>
                <div class="sg-field"><label>Кнопки: Название | URL</label><textarea name="settings[welcome][buttons_text]" rows="3">@foreach($bot->moduleSetting('welcome','buttons',[]) as $row)@foreach($row as $button){{ $button['text'] ?? '' }} | {{ $button['url'] ?? '' }}&#10;@endforeach @endforeach</textarea></div>
                <div class="sg-field"><label>Удалить приветствие через минут</label><input name="settings[welcome][delete_after_minutes]" type="number" value="{{ $bot->moduleSetting('welcome','delete_after_minutes') }}"></div>
            </fieldset>
        @endif

        @if($bot->moduleEnabled('subscription_check'))
            <fieldset><legend>Проверка подписки</legend><div class="sg-field"><label>Обязательные каналы</label><textarea name="settings[subscription_check][channels_text]" rows="4">{{ implode("\n", $bot->moduleSetting('subscription_check','channels',[])) }}</textarea></div></fieldset>
        @endif

        @if($bot->moduleEnabled('join_requests'))
            <fieldset><legend>Заявки на вступление</legend>
                <label class="sg-switch-row"><strong>Автоматически одобрять подходящие заявки</strong><input type="checkbox" name="settings[join_requests][auto_approve]" value="1" @checked($bot->moduleSetting('join_requests','auto_approve'))></label>
                <label class="sg-switch-row"><strong>Автоматически отклонять ботов</strong><input type="checkbox" name="settings[join_requests][auto_decline_bots]" value="1" @checked($bot->moduleSetting('join_requests','auto_decline_bots',true))></label>
            </fieldset>
        @endif

        @if($bot->moduleEnabled('human_verification'))
            <fieldset><legend>Проверка новых участников</legend>
                <div class="sg-form-grid">
                    <div class="sg-field"><label>Способ</label><select name="settings[human_verification][mode]"><option value="button" @selected($bot->moduleSetting('human_verification','mode')==='button')>Кнопка «Я человек»</option><option value="question" @selected($bot->moduleSetting('human_verification','mode')==='question')>Вопрос</option><option value="captcha" @selected($bot->moduleSetting('human_verification','mode')==='captcha')>Капча-пример</option></select></div>
                    <div class="sg-field"><label>Лимит времени, минут</label><input name="settings[human_verification][timeout_minutes]" type="number" value="{{ $bot->moduleSetting('human_verification','timeout_minutes',5) }}"></div>
                </div>
                <div class="sg-field"><label>Вопрос</label><input name="settings[human_verification][question]" value="{{ $bot->moduleSetting('human_verification','question','') }}"></div>
                <div class="sg-field"><label>Правильный ответ</label><input name="settings[human_verification][answer]" value="{{ $bot->moduleSetting('human_verification','answer','') }}"></div>
            </fieldset>
        @endif

        @if($bot->moduleEnabled('warnings'))
            <fieldset><legend>Предупреждения</legend><div class="sg-form-grid">
                <div class="sg-field"><label>Мут после предупреждения №</label><input name="settings[warnings][mute_after]" type="number" value="{{ $bot->moduleSetting('warnings','mute_after',2) }}"></div>
                <div class="sg-field"><label>Длительность мута, минут</label><input name="settings[warnings][mute_minutes]" type="number" value="{{ $bot->moduleSetting('warnings','mute_minutes',60) }}"></div>
                <div class="sg-field"><label>Бан после предупреждения №</label><input name="settings[warnings][ban_after]" type="number" value="{{ $bot->moduleSetting('warnings','ban_after',3) }}"></div>
            </div></fieldset>
        @endif

        @if($bot->moduleEnabled('newcomer_restrictions'))
            <fieldset><legend>Ограничения новичков</legend>
                <div class="sg-field"><label>Длительность, минут</label><input name="settings[newcomer_restrictions][minutes]" type="number" value="{{ $bot->moduleSetting('newcomer_restrictions','minutes',10) }}"></div>
                <label class="sg-switch-row"><strong>Запрет ссылок</strong><input type="checkbox" name="settings[newcomer_restrictions][block_links]" value="1" @checked($bot->moduleSetting('newcomer_restrictions','block_links',true))></label>
                <label class="sg-switch-row"><strong>Запрет файлов</strong><input type="checkbox" name="settings[newcomer_restrictions][block_files]" value="1" @checked($bot->moduleSetting('newcomer_restrictions','block_files'))></label>
                <label class="sg-switch-row"><strong>Запрет всех сообщений</strong><input type="checkbox" name="settings[newcomer_restrictions][block_messages]" value="1" @checked($bot->moduleSetting('newcomer_restrictions','block_messages'))></label>
            </fieldset>
        @endif

        @if($bot->moduleEnabled('slow_mode'))
            <fieldset><legend>Медленный режим</legend><div class="sg-form-grid"><div class="sg-field"><label>Сообщений</label><input name="settings[slow_mode][messages]" type="number" value="{{ $bot->moduleSetting('slow_mode','messages',0) }}"></div><div class="sg-field"><label>За период, секунд</label><input name="settings[slow_mode][period_seconds]" type="number" value="{{ $bot->moduleSetting('slow_mode','period_seconds',60) }}"></div></div></fieldset>
        @endif

        <div class="sg-record-actions"><button class="sg-button sg-button-primary" type="submit">Сохранить настройки</button></div>
    </form>
@endif

@if($bot->moduleEnabled('bulk_delete'))
    <hr>
    <h3>Массовое удаление</h3>
    <p>Предпросмотр строится по сообщениям, полученным после подключения webhook.</p>
    <form method="POST" action="{{ route('admin.group-channel.bulk-delete.preview', $bot) }}">@csrf
        <div class="sg-form-grid">
            <div class="sg-field"><label>Критерий</label><select name="mode"><option value="last">Последние сообщения</option><option value="period">За период</option><option value="user">Конкретного пользователя</option><option value="links">Со ссылками</option><option value="forbidden">С запрещёнными словами</option></select></div>
            <div class="sg-field"><label>Количество последних</label><select name="count"><option value="10">10</option><option value="50">50</option><option value="100">100</option></select></div>
            <div class="sg-field"><label>Дата от</label><input name="date_from" type="datetime-local"></div>
            <div class="sg-field"><label>Дата до</label><input name="date_to" type="datetime-local"></div>
            <div class="sg-field"><label>ID пользователя</label><input name="user_id"></div>
            <div class="sg-field"><label>Запрещённое слово</label><input name="forbidden_word"></div>
        </div>
        <button class="sg-button sg-button-secondary" type="submit">Показать количество</button>
    </form>
@endif

<div class="sg-danger-zone">
    <div><strong>Удаление</strong><p>Бот и привязка к группе/каналу будут удалены.</p></div>
    <form method="POST" action="{{ route('admin.group-channel.destroy', $bot) }}" data-confirm="Удалить эту запись?">@csrf @method('DELETE')<button class="sg-button sg-button-danger" type="submit">Удалить</button></form>
</div>
