<x-layouts.admin title="Ставки">
    <div class="betting-shell">
        <nav class="betting-tabs" aria-label="Разделы ставок">
            @foreach([
                'overview' => ['◫', 'Обзор'], 'search' => ['⌕', 'Поиск ставок'],
                'published' => ['✓', 'Опубликованные'], 'statistics' => ['▥', 'Статистика'],
                'sources' => ['⌁', 'Источники'], 'channels' => ['➤', 'Каналы публикации'],
                'settings' => ['⚙', 'Настройки'],
            ] as $key => [$icon, $label])
                <a href="{{ route('admin.betting.index', ['tab' => $key]) }}" @class(['is-active' => $tab === $key])><span>{{ $icon }}</span>{{ $label }}</a>
            @endforeach
        </nav>

        @if($tab === 'overview')
            <div class="betting-stats">
                @foreach([
                    ['Всего опубликовано', $statistics['total'], 'neutral'], ['Ожидают результата', $statistics['pending'], 'warning'],
                    ['Выигрышей', $statistics['wins'], 'success'], ['Проигрышей', $statistics['losses'], 'error'],
                    ['Возвратов', $statistics['refunds'], 'neutral'], ['Проходимость', $statistics['success_rate'].'%', 'success'],
                ] as [$label, $value, $tone])
                    <article class="betting-stat is-{{ $tone }}"><span>{{ $label }}</span><strong>{{ $value }}</strong></article>
                @endforeach
            </div>
            <section class="sg-section-block">
                <div class="sg-section-heading"><div><p class="sg-eyebrow">Последние данные</p><h2>Найденные ставки</h2><p>Модуль не работает в фоне. Новый поиск запускается только вручную.</p></div>
                    <form method="POST" action="{{ route('admin.betting.search') }}">@csrf<button class="sg-button sg-button-primary" type="submit">⌕ Проверить ставки</button></form>
                </div>
                @forelse($foundBets->take(3) as $bet)<x-betting.bet-card :bet="$bet" :bots="$bots" />@empty<div class="sg-compact-empty">Подходящих ставок пока нет.</div>@endforelse
            </section>
        @endif

        @if($tab === 'search')
            <section class="sg-section-block">
                <div class="sg-section-heading"><div><p class="sg-eyebrow">Ручной запуск</p><h2>Поиск ставок в Telegram</h2><p>После завершения поиск и все связанные процессы полностью останавливаются.</p></div>
                    <form method="POST" action="{{ route('admin.betting.search') }}">@csrf<button class="sg-button sg-button-primary" type="submit">⌕ Проверить ставки</button></form>
                </div>
                @if($latestRun)<div class="betting-run"><span class="sg-status sg-status-{{ $latestRun->status === 'completed' ? 'success' : ($latestRun->status === 'error' ? 'error' : 'warning') }}">{{ $latestRun->status === 'completed' ? 'Завершено' : ($latestRun->status === 'error' ? 'Ошибка' : 'Выполняется') }}</span><span>Сообщений: <b>{{ $latestRun->messages_found }}</b></span><span>Ставок: <b>{{ $latestRun->bets_found }}</b></span><span>{{ optional($latestRun->finished_at)->format('d.m.Y H:i') }}</span></div>@endif
            </section>
            <div class="betting-list">
                @forelse($foundBets as $bet)<x-betting.bet-card :bet="$bet" :bots="$bots" />@empty<div class="sg-empty-state sg-empty-state-compact"><div class="sg-empty-symbol"><span>⌕</span></div><h2>Список пуст</h2><p>Нажмите «Проверить ставки», чтобы выполнить поиск.</p></div>@endforelse
            </div>
            <div class="sg-pagination">{{ $foundBets->withQueryString()->links() }}</div>
        @endif

        @if($tab === 'published')
            <div class="betting-list">
                @forelse($publishedBets as $bet)<x-betting.bet-card :bet="$bet" :bots="$bots" published />@empty<div class="sg-empty-state sg-empty-state-compact"><h2>Нет публикаций</h2><p>Одобренные ставки появятся здесь.</p></div>@endforelse
            </div>
            <div class="sg-pagination">{{ $publishedBets->withQueryString()->links() }}</div>
        @endif

        @if($tab === 'statistics')
            <div class="betting-stats betting-stats-large">
                @foreach([['Всего завершено', $statistics['wins'] + $statistics['losses'] + $statistics['refunds'], 'neutral'], ['Выигрышей', $statistics['wins'], 'success'], ['Проигрышей', $statistics['losses'], 'error'], ['Возвратов', $statistics['refunds'], 'neutral'], ['Проходимость', $statistics['success_rate'].'%', 'success']] as [$label, $value, $tone])
                    <article class="betting-stat is-{{ $tone }}"><span>{{ $label }}</span><strong>{{ $value }}</strong></article>
                @endforeach
            </div>
            <div class="sg-notice sg-notice-warning">Проходимость = выигрыши / (выигрыши + проигрыши) × 100%. Возвраты не учитываются.</div>
        @endif

        @if(in_array($tab, ['sources', 'channels', 'settings'], true))
            <form class="betting-settings" method="POST" action="{{ route('admin.betting.settings.update') }}">@csrf @method('PUT')
                <input type="hidden" name="technical_account_id" value="{{ old('technical_account_id', $settings->technical_account_id) }}">
                <input type="hidden" name="publication_bot_id" value="{{ old('publication_bot_id', $settings->publication_bot_id) }}">
                <input type="hidden" name="keywords_text" value="{{ old('keywords_text', implode("\n", $settings->keywords ?? [])) }}">
                <input type="hidden" name="freshness_hours" value="{{ old('freshness_hours', $settings->freshness_hours) }}">
                <input type="hidden" name="minimum_ai_score" value="{{ old('minimum_ai_score', $settings->minimum_ai_score) }}">
                <input type="hidden" name="maximum_results" value="{{ old('maximum_results', $settings->maximum_results) }}">
                <input type="hidden" name="primary_source_name" value="{{ old('primary_source_name', $settings->primary_source_name) }}">
                <input type="hidden" name="primary_source_url" value="{{ old('primary_source_url', $settings->primary_source_url) }}">
                <input type="hidden" name="reserve_source_name" value="{{ old('reserve_source_name', $settings->reserve_source_name) }}">
                <input type="hidden" name="reserve_source_url" value="{{ old('reserve_source_url', $settings->reserve_source_url) }}">
                <input type="hidden" name="found_retention_days" value="{{ old('found_retention_days', $settings->found_retention_days) }}">
                <input type="hidden" name="rejected_retention_days" value="{{ old('rejected_retention_days', $settings->rejected_retention_days) }}">
                <input type="hidden" name="completed_retention_days" value="{{ old('completed_retention_days', $settings->completed_retention_days) }}">

                @if($tab === 'sources')
                    <section class="sg-section-block"><div class="sg-section-heading"><div><p class="sg-eyebrow">Коэффициенты и события</p><h2>Источники проверки</h2><p>Основной источник используется первым. Резервный — если данные основного недоступны.</p></div></div>
                        <div class="sg-form-grid">
                            <label class="sg-field"><span>Основной источник</span><input name="primary_source_name" value="{{ old('primary_source_name', $settings->primary_source_name) }}" required></label>
                            <label class="sg-field"><span>Адрес основного источника</span><input type="url" name="primary_source_url" value="{{ old('primary_source_url', $settings->primary_source_url) }}" required></label>
                            <label class="sg-field"><span>Резервный источник</span><input name="reserve_source_name" value="{{ old('reserve_source_name', $settings->reserve_source_name) }}" placeholder="Например, резервный букмекер"></label>
                            <label class="sg-field"><span>Адрес резервного источника</span><input type="url" name="reserve_source_url" value="{{ old('reserve_source_url', $settings->reserve_source_url) }}" placeholder="https://"></label>
                        </div>
                    </section>
                @elseif($tab === 'channels')
                    <section class="sg-section-block"><div class="sg-section-heading"><div><p class="sg-eyebrow">Telegram</p><h2>Аккаунт и канал</h2><p>Техаккаунт ищет ставки, бот публикует одобренные ставки и результаты.</p></div></div>
                        <div class="sg-form-grid">
                            <label class="sg-field"><span>Технический аккаунт «Ставки»</span><select name="technical_account_id"><option value="">Не выбран</option>@foreach($technicalAccounts as $account)<option value="{{ $account->id }}" @selected((string) old('technical_account_id', $settings->technical_account_id) === (string) $account->id)>{{ $account->name }} — {{ $account->status === 'connected' ? 'подключён' : $account->status }}</option>@endforeach</select></label>
                            <label class="sg-field"><span>Канал публикации по умолчанию</span><select name="publication_bot_id"><option value="">Не выбран</option>@foreach($bots as $bot)<option value="{{ $bot->id }}" @selected((string) old('publication_bot_id', $settings->publication_bot_id) === (string) $bot->id)>{{ $bot->group_name }} · {{ $bot->bot_name }}</option>@endforeach</select></label>
                        </div>
                    </section>
                @else
                    <section class="sg-section-block"><div class="sg-section-heading"><div><p class="sg-eyebrow">Поиск и отбор</p><h2>Основные параметры</h2></div></div>
                        <div class="sg-form-grid">
                            <label class="sg-field sg-field-wide"><span>Ключевые слова — по одному в строке</span><textarea name="keywords_text" rows="7" required>{{ old('keywords_text', implode("\n", $settings->keywords ?? [])) }}</textarea></label>
                            <label class="sg-field"><span>Свежесть публикаций, часов</span><input type="number" name="freshness_hours" min="1" max="720" value="{{ old('freshness_hours', $settings->freshness_hours) }}" required></label>
                            <label class="sg-field"><span>Максимум вариантов</span><input type="number" name="maximum_results" min="1" max="100" value="{{ old('maximum_results', $settings->maximum_results) }}" required></label>
                            <label class="sg-field"><span>Минимальная оценка AI, %</span><input type="number" name="minimum_ai_score" min="1" max="100" value="{{ old('minimum_ai_score', $settings->minimum_ai_score) }}" required></label>
                        </div>
                    </section>
                    <section class="sg-section-block"><div class="sg-section-heading"><div><p class="sg-eyebrow">Очистка базы</p><h2>Сроки хранения</h2></div></div>
                        <div class="sg-form-grid">
                            <label class="sg-field"><span>Найденные, дней</span><input type="number" name="found_retention_days" min="1" max="3650" value="{{ old('found_retention_days', $settings->found_retention_days) }}" required></label>
                            <label class="sg-field"><span>Отклонённые, дней</span><input type="number" name="rejected_retention_days" min="1" max="3650" value="{{ old('rejected_retention_days', $settings->rejected_retention_days) }}" required></label>
                            <label class="sg-field"><span>Завершённые, дней</span><input type="number" name="completed_retention_days" min="1" max="3650" value="{{ old('completed_retention_days', $settings->completed_retention_days) }}" placeholder="Пусто — хранить постоянно"></label>
                        </div>
                    </section>
                @endif
                @if($errors->any())<div class="sg-inline-error">{{ $errors->first() }}</div>@endif
                <div class="betting-save"><button class="sg-button sg-button-primary" type="submit">Сохранить настройки</button></div>
            </form>

            @if($tab === 'settings')
                <section class="sg-section-block">
                    <div class="sg-section-heading"><div><p class="sg-eyebrow">Ручное обслуживание</p><h2>Очистка архива</h2><p>Удаление выполняется только по действию администратора. Опубликованные ставки с ожидающим результатом не удаляются.</p></div></div>
                    <form class="betting-cleanup" method="POST" action="{{ route('admin.betting.archive.clear') }}">
                        @csrf @method('DELETE')
                        <label class="sg-field"><span>Какие данные удалить</span><select name="scope" required><option value="found">Найденные ставки</option><option value="rejected">Отклонённые ставки</option><option value="completed">Завершённые опубликованные ставки</option><option value="search_runs">История запусков поиска</option></select></label>
                        <button class="sg-button sg-button-danger" type="submit">Очистить выбранное</button>
                    </form>
                </section>
            @endif
        @endif
    </div>
</x-layouts.admin>
