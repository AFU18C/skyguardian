@if($module === 'publications')
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
        <div class="sg-module-history">
            <h4>Последние публикации</h4>
            @foreach($bot->publications as $publication)
                <div class="sg-switch-row">
                    <div>
                        <strong>#{{ $publication->id }} · {{ $publication->type }} · {{ $publication->status }}</strong>
                        <small>{{ \Illuminate\Support\Str::limit($publication->text, 100) }}</small>
                        @if($publication->scheduled_at)<small>Отправка: {{ $publication->scheduled_at->timezone('Europe/Kyiv')->format('d.m.Y H:i') }}</small>@endif
                        @if($publication->last_error)<div class="sg-inline-error">{{ $publication->last_error }}</div>@endif
                    </div>
                    <div class="sg-record-actions">
                        @if($publication->status === \App\Models\GroupChannelPublication::STATUS_UNCERTAIN)
                            <form method="POST" action="{{ route('admin.group-channel.publications.resolve', [$bot, $publication]) }}">
                                @csrf
                                <input type="hidden" name="resolution" value="sent">
                                <input type="number" name="telegram_message_id" min="1" value="{{ $publication->telegram_message_id }}" placeholder="ID сообщения" @required(empty($publication->telegram_message_ids))>
                                <button class="sg-button sg-button-small sg-button-primary" type="submit">Подтвердить отправку</button>
                            </form>
                            <form method="POST" action="{{ route('admin.group-channel.publications.resolve', [$bot, $publication]) }}" data-confirm="Вы проверили канал и уверены, что сообщение не опубликовано?">
                                @csrf
                                <input type="hidden" name="resolution" value="retry">
                                <button class="sg-button sg-button-small sg-button-secondary" type="submit">Разрешить повтор</button>
                            </form>
                        @endif
                        @if(in_array($publication->status, ['draft', 'error'], true))
                            <form method="POST" action="{{ route('admin.group-channel.publications.send', [$bot, $publication]) }}">@csrf<button class="sg-button sg-button-small sg-button-secondary" type="submit">Отправить</button></form>
                        @endif
                        @if(!in_array($publication->status, [\App\Models\GroupChannelPublication::STATUS_SENDING, \App\Models\GroupChannelPublication::STATUS_UNCERTAIN], true))
                            <form method="POST" action="{{ route('admin.group-channel.publications.destroy', [$bot, $publication]) }}" data-confirm="Удалить публикацию из SkyGuardian?">@csrf @method('DELETE')<button class="sg-button sg-button-small sg-button-danger" type="submit">Удалить</button></form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

@elseif($module === 'bulk_delete')
    <p>Предпросмотр строится по сообщениям, полученным после подключения webhook.</p>
    <form method="POST" action="{{ route('admin.group-channel.bulk-delete.preview', $bot) }}">
        @csrf
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

@elseif($module === 'system_messages')
    <form method="POST" action="{{ route('admin.group-channel.module-settings.update', $bot) }}">
        @csrf @method('PUT')
        <input type="hidden" name="module" value="system_messages">
        <p>Бот удаляет только служебные сообщения Telegram. Обычные сообщения участников не затрагиваются.</p>
        <label class="sg-switch-row"><strong>Вступил / добавлен / вышел / удалён</strong><input type="checkbox" name="settings[system_messages][member_events]" value="1" @checked($bot->moduleSetting('system_messages','member_events',true))></label>
        <label class="sg-switch-row"><strong>Закрепил сообщение</strong><input type="checkbox" name="settings[system_messages][pinned_messages]" value="1" @checked($bot->moduleSetting('system_messages','pinned_messages',true))></label>
        <label class="sg-switch-row"><strong>Изменил название, фото или автоудаление</strong><input type="checkbox" name="settings[system_messages][chat_changes]" value="1" @checked($bot->moduleSetting('system_messages','chat_changes',true))></label>
        <label class="sg-switch-row"><strong>Запустил или завершил видеочат</strong><input type="checkbox" name="settings[system_messages][video_chats]" value="1" @checked($bot->moduleSetting('system_messages','video_chats',true))></label>
        <label class="sg-switch-row"><strong>Создал или изменил тему форума</strong><input type="checkbox" name="settings[system_messages][forum_topics]" value="1" @checked($bot->moduleSetting('system_messages','forum_topics',true))></label>
        <label class="sg-switch-row"><strong>Другие системные события</strong><input type="checkbox" name="settings[system_messages][other_events]" value="1" @checked($bot->moduleSetting('system_messages','other_events',true))></label>
        <div class="sg-record-actions"><button class="sg-button sg-button-primary" type="submit">Сохранить удаление системных сообщений</button></div>
    </form>

@elseif($module === 'antispam')
    <form method="POST" action="{{ route('admin.group-channel.module-settings.update', $bot) }}">
        @csrf @method('PUT')
        <input type="hidden" name="module" value="antispam">
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
        <div class="sg-record-actions"><button class="sg-button sg-button-primary" type="submit">Сохранить антиспам</button></div>
    </form>

@elseif($module === 'welcome')
    <form method="POST" action="{{ route('admin.group-channel.module-settings.update', $bot) }}">
        @csrf @method('PUT')
        <input type="hidden" name="module" value="welcome">
        <div class="sg-field"><label>Текст</label><textarea name="settings[welcome][text]" rows="5">{{ $bot->moduleSetting('welcome','text','') }}</textarea></div>
        <div class="sg-field"><label>Правила</label><textarea name="settings[welcome][rules]" rows="4">{{ $bot->moduleSetting('welcome','rules','') }}</textarea></div>
        <div class="sg-field"><label>Кнопки: Название | URL</label><textarea name="settings[welcome][buttons_text]" rows="3">@foreach($bot->moduleSetting('welcome','buttons',[]) as $row)@foreach($row as $button){{ $button['text'] ?? '' }} | {{ $button['url'] ?? '' }}&#10;@endforeach @endforeach</textarea></div>
        <div class="sg-field"><label>Удалить приветствие через минут</label><input name="settings[welcome][delete_after_minutes]" type="number" value="{{ $bot->moduleSetting('welcome','delete_after_minutes') }}"></div>
        <div class="sg-record-actions"><button class="sg-button sg-button-primary" type="submit">Сохранить приветствие</button></div>
    </form>
    <hr>
    <h4>Фото приветствия</h4>
    <form method="POST" action="{{ route('admin.group-channel.welcome-photo.update', $bot) }}" enctype="multipart/form-data">
        @csrf
        <div class="sg-form-grid">
            <div class="sg-field"><label for="welcome-photo-{{ $bot->id }}">Фото</label><input id="welcome-photo-{{ $bot->id }}" name="photo" type="file" accept="image/*" required><small>{{ $bot->moduleSetting('welcome', 'photo') ? 'Фото уже загружено. Новое заменит текущее.' : 'Фото ещё не загружено.' }}</small></div>
            <div class="sg-record-actions"><button class="sg-button sg-button-secondary" type="submit">Сохранить фото</button></div>
        </div>
    </form>
    @if($bot->moduleSetting('welcome', 'photo'))
        <form method="POST" action="{{ route('admin.group-channel.welcome-photo.destroy', $bot) }}" data-confirm="Удалить фото приветствия?">@csrf @method('DELETE')<button class="sg-button sg-button-danger" type="submit">Удалить фото</button></form>
    @endif

@elseif($module === 'subscription_check')
    <form method="POST" action="{{ route('admin.group-channel.module-settings.update', $bot) }}">
        @csrf @method('PUT')
        <input type="hidden" name="module" value="subscription_check">
        <div class="sg-field"><label>Обязательные каналы</label><textarea name="settings[subscription_check][channels_text]" rows="4">{{ implode("\n", $bot->moduleSetting('subscription_check','channels',[])) }}</textarea></div>
        <div class="sg-record-actions"><button class="sg-button sg-button-primary" type="submit">Сохранить проверку подписки</button></div>
    </form>

@elseif($module === 'join_requests')
    <form method="POST" action="{{ route('admin.group-channel.module-settings.update', $bot) }}">
        @csrf @method('PUT')
        <input type="hidden" name="module" value="join_requests">
        <label class="sg-switch-row"><strong>Автоматически одобрять подходящие заявки</strong><input type="checkbox" name="settings[join_requests][auto_approve]" value="1" @checked($bot->moduleSetting('join_requests','auto_approve'))></label>
        <label class="sg-switch-row"><strong>Автоматически отклонять ботов</strong><input type="checkbox" name="settings[join_requests][auto_decline_bots]" value="1" @checked($bot->moduleSetting('join_requests','auto_decline_bots',true))></label>
        <div class="sg-record-actions"><button class="sg-button sg-button-primary" type="submit">Сохранить заявки</button></div>
    </form>
    <hr>
    <h4>Ожидающие заявки</h4>
    @php
        $pendingJoinRequests = $bot->joinRequests()->where('status', \App\Models\GroupChannelJoinRequest::STATUS_PENDING)->latest('requested_at')->limit(50)->get();
    @endphp
    @forelse($pendingJoinRequests as $joinRequest)
        <div class="sg-switch-row">
            <div><strong>{{ trim($joinRequest->first_name.' '.$joinRequest->last_name) ?: 'Пользователь '.$joinRequest->telegram_user_id }}</strong><small>ID: {{ $joinRequest->telegram_user_id }} @if($joinRequest->username) · {{ '@'.$joinRequest->username }} @endif</small></div>
            <div class="sg-record-actions">
                <form method="POST" action="{{ route('admin.group-channel.join-requests.approve', [$bot, $joinRequest]) }}">@csrf<button class="sg-button sg-button-small sg-button-primary" type="submit">Одобрить</button></form>
                <form method="POST" action="{{ route('admin.group-channel.join-requests.decline', [$bot, $joinRequest]) }}">@csrf<button class="sg-button sg-button-small sg-button-danger" type="submit">Отказать</button></form>
            </div>
        </div>
    @empty
        <p>Ожидающих заявок нет.</p>
    @endforelse

@elseif($module === 'human_verification')
    <form method="POST" action="{{ route('admin.group-channel.module-settings.update', $bot) }}">
        @csrf @method('PUT')
        <input type="hidden" name="module" value="human_verification">
        <div class="sg-form-grid">
            <div class="sg-field"><label>Способ</label><select name="settings[human_verification][mode]"><option value="button" @selected($bot->moduleSetting('human_verification','mode')==='button')>Кнопка «Я человек»</option><option value="question" @selected($bot->moduleSetting('human_verification','mode')==='question')>Вопрос</option><option value="captcha" @selected($bot->moduleSetting('human_verification','mode')==='captcha')>Капча-пример</option></select></div>
            <div class="sg-field"><label>Лимит времени, минут</label><input name="settings[human_verification][timeout_minutes]" type="number" value="{{ $bot->moduleSetting('human_verification','timeout_minutes',5) }}"></div>
        </div>
        <div class="sg-field"><label>Вопрос</label><input name="settings[human_verification][question]" value="{{ $bot->moduleSetting('human_verification','question','') }}"></div>
        <div class="sg-field"><label>Правильный ответ</label><input name="settings[human_verification][answer]" value="{{ $bot->moduleSetting('human_verification','answer','') }}"></div>
        <div class="sg-record-actions"><button class="sg-button sg-button-primary" type="submit">Сохранить проверку</button></div>
    </form>

@elseif($module === 'warnings')
    <form method="POST" action="{{ route('admin.group-channel.module-settings.update', $bot) }}">
        @csrf @method('PUT')
        <input type="hidden" name="module" value="warnings">
        <div class="sg-form-grid">
            <div class="sg-field"><label>Мут после предупреждения №</label><input name="settings[warnings][mute_after]" type="number" value="{{ $bot->moduleSetting('warnings','mute_after',3) }}"></div>
            <div class="sg-field"><label>Длительность мута, минут</label><input name="settings[warnings][mute_minutes]" type="number" value="{{ $bot->moduleSetting('warnings','mute_minutes',60) }}"></div>
            <div class="sg-field"><label>Бан после предупреждения №</label><input name="settings[warnings][ban_after]" type="number" value="{{ $bot->moduleSetting('warnings','ban_after',4) }}"></div>
        </div>
        <div class="sg-record-actions"><button class="sg-button sg-button-primary" type="submit">Сохранить наказания</button></div>
    </form>

@elseif($module === 'newcomer_restrictions')
    <form method="POST" action="{{ route('admin.group-channel.module-settings.update', $bot) }}">
        @csrf @method('PUT')
        <input type="hidden" name="module" value="newcomer_restrictions">
        <div class="sg-field"><label>Длительность, минут</label><input name="settings[newcomer_restrictions][minutes]" type="number" value="{{ $bot->moduleSetting('newcomer_restrictions','minutes',10) }}"></div>
        <label class="sg-switch-row"><strong>Запрет ссылок</strong><input type="checkbox" name="settings[newcomer_restrictions][block_links]" value="1" @checked($bot->moduleSetting('newcomer_restrictions','block_links',true))></label>
        <label class="sg-switch-row"><strong>Запрет файлов</strong><input type="checkbox" name="settings[newcomer_restrictions][block_files]" value="1" @checked($bot->moduleSetting('newcomer_restrictions','block_files'))></label>
        <label class="sg-switch-row"><strong>Запрет всех сообщений</strong><input type="checkbox" name="settings[newcomer_restrictions][block_messages]" value="1" @checked($bot->moduleSetting('newcomer_restrictions','block_messages'))></label>
        <div class="sg-record-actions"><button class="sg-button sg-button-primary" type="submit">Сохранить ограничения</button></div>
    </form>

@elseif($module === 'slow_mode')
    <form method="POST" action="{{ route('admin.group-channel.module-settings.update', $bot) }}">
        @csrf @method('PUT')
        <input type="hidden" name="module" value="slow_mode">
        <div class="sg-form-grid">
            <div class="sg-field"><label>Сообщений</label><input name="settings[slow_mode][messages]" type="number" value="{{ $bot->moduleSetting('slow_mode','messages',0) }}"></div>
            <div class="sg-field"><label>За период, секунд</label><input name="settings[slow_mode][period_seconds]" type="number" value="{{ $bot->moduleSetting('slow_mode','period_seconds',60) }}"></div>
        </div>
        <div class="sg-record-actions"><button class="sg-button sg-button-primary" type="submit">Сохранить медленный режим</button></div>
    </form>
@endif
