@props(['bet', 'bots' => collect(), 'published' => false])
@php
    $searchSources = collect($bet->search_sources ?: $bet->telegram_sources ?: []);
    $telegramSourceCount = $searchSources->filter(fn ($source) => ($source['type'] ?? 'telegram') === 'telegram')->count();
    $websiteSourceCount = $searchSources->where('type', 'website')->count();
@endphp

<article class="bet-card">
    <div class="bet-card-main">
        <div class="bet-card-icon">{{ match($bet->sport) {'Баскетбол' => '🏀', 'Хоккей' => '🏒', 'Теннис' => '🎾', 'Киберспорт' => '🎮', default => '⚽'} }}</div>
        <div class="bet-card-title">
            <div class="bet-card-kicker"><span>{{ $bet->sport ?: 'Спорт' }}</span>@if($bet->tournament)<span>{{ $bet->tournament }}</span>@endif</div>
            <h3>{{ $bet->event_name }}</h3>
            @if($bet->starts_at)<p>📅 {{ $bet->starts_at->format('d.m.Y') }} · {{ $bet->starts_at->format('H:i') }}</p>@endif
        </div>
        @if($published)
            <span @class(['sg-status', 'sg-status-success' => $bet->result === 'win', 'sg-status-error' => $bet->result === 'loss', 'sg-status-neutral' => in_array($bet->result, ['refund', null]), 'sg-status-warning' => $bet->result === 'pending'])>
                {{ match($bet->result) {'win' => 'Выигрыш', 'loss' => 'Проигрыш', 'refund' => 'Возврат', default => 'Ожидает'} }}
            </span>
        @else
            <span class="bet-ai-score">Качество <b>{{ $bet->ai_score }}%</b></span>
        @endif
    </div>

    <div class="bet-card-data">
        <div><span>Прогноз</span><strong>{{ $bet->market }}</strong></div>
        <div><span>Из публикации</span><strong>{{ $bet->telegram_odds ? number_format((float) $bet->telegram_odds, 2) : '—' }}</strong></div>
        <div><span>{{ data_get($bet->odds_snapshot, 'primary.name') ?: 'Основной' }}</span><strong>{{ $bet->primary_odds ? number_format((float) $bet->primary_odds, 2) : 'не найден' }}</strong></div>
        <div><span>{{ data_get($bet->odds_snapshot, 'reserve.name') ?: 'Резервный' }}</span><strong>{{ $bet->reserve_odds ? number_format((float) $bet->reserve_odds, 2) : 'не найден' }}</strong></div>
        @if($published)<div><span>Опубликован</span><strong>{{ optional($bet->published_at)->format('d.m.Y H:i') ?: '—' }}</strong></div>@endif
    </div>

    @if(!$published)
        <p class="bet-reason">{{ $bet->ai_reason }}</p>
        <div class="bet-sources">Источники: Telegram — <b>{{ $telegramSourceCount }}</b>, сайты — <b>{{ $websiteSourceCount }}</b> · Проверено: {{ optional($bet->odds_checked_at)->format('d.m.Y H:i') ?: '—' }}</div>
        <div class="bet-source-health">
            @foreach(['primary' => 'Основной', 'reserve' => 'Резервный'] as $sourceKey => $fallbackLabel)
                @php($snapshot = data_get($bet->odds_snapshot, $sourceKey, []))
                <span @class(['is-ok' => data_get($snapshot, 'event_found'), 'is-error' => data_get($snapshot, 'error')])>
                    {{ data_get($snapshot, 'name') ?: $fallbackLabel }}:
                    {{ empty(data_get($snapshot, 'url')) ? 'не настроен' : (data_get($snapshot, 'error') ?: (data_get($snapshot, 'event_found') ? 'событие найдено' : 'событие не найдено')) }}
                </span>
            @endforeach
        </div>
        @if($bet->status === \App\Models\Bet::STATUS_PUBLICATION_UNCERTAIN)
            <div class="sg-notice sg-notice-warning bet-manual-result-note">
                <strong>Telegram не подтвердил отправку.</strong> {{ $bet->publication_error }} Сначала проверьте канал; автоматический повтор заблокирован.
            </div>
            <div class="bet-card-actions">
                <form method="POST" action="{{ route('admin.betting.resolve-publication', $bet) }}">
                    @csrf
                    <input type="hidden" name="resolution" value="published">
                    <label><span>ID сообщения в Telegram</span><input type="number" name="telegram_message_id" min="1" value="{{ $bet->telegram_message_id }}" required></label>
                    <button class="sg-button sg-button-primary sg-button-small" type="submit">Подтвердить публикацию</button>
                </form>
                <form method="POST" action="{{ route('admin.betting.resolve-publication', $bet) }}" data-confirm="Вы проверили канал и уверены, что ставка не опубликована?">
                    @csrf
                    <input type="hidden" name="resolution" value="retry">
                    <button class="sg-button sg-button-secondary sg-button-small" type="submit">Разрешить повтор</button>
                </form>
            </div>
        @else
        <div class="bet-card-actions">
            <form class="bet-approve-form" method="POST" action="{{ route('admin.betting.approve', $bet) }}">@csrf
                <label><span>Коэффициент публикации</span><input type="number" name="selected_odds" min="1.001" max="9999" step="0.001" value="{{ $bet->selected_odds ?: $bet->primary_odds ?: $bet->reserve_odds }}" placeholder="Укажите свой"></label>
                <label><span>Канал</span><select name="publication_bot_id"><option value="">По умолчанию</option>@foreach($bots as $bot)<option value="{{ $bot->id }}">{{ $bot->group_name }}</option>@endforeach</select></label>
                <button class="sg-button sg-button-primary sg-button-small" type="submit">✓ Одобрить и опубликовать</button>
            </form>
            <form method="POST" action="{{ route('admin.betting.reject', $bet) }}">@csrf<button class="sg-button sg-button-danger sg-button-small" type="submit">× Отклонить</button></form>
        </div>
        <details class="bet-details"><summary>Источники найденной ставки</summary><div>@foreach($searchSources as $source)<p><b>{{ ($source['type'] ?? 'telegram') === 'website' ? 'Сайт: '.($source['name'] ?? 'Источник') : 'Telegram: '.($source['chat_title'] ?? $source['name'] ?? 'Канал') }}</b> @if(!empty($source['url']))<a href="{{ $source['url'] }}" target="_blank" rel="noopener">Открыть источник ↗</a>@endif<br><small>{{ $source['text'] ?? '' }}</small></p>@endforeach</div></details>
        <details class="bet-details"><summary>Редактировать перед публикацией</summary>
            <form class="sg-form bet-edit-form" method="POST" action="{{ route('admin.betting.update', $bet) }}">@csrf @method('PUT')
                <div class="sg-form-grid">
                    <label class="sg-field"><span>Событие</span><input name="event_name" value="{{ $bet->event_name }}" required></label>
                    <label class="sg-field"><span>Турнир</span><input name="tournament" value="{{ $bet->tournament }}"></label>
                    <label class="sg-field"><span>Дата и время</span><input type="datetime-local" name="starts_at" value="{{ optional($bet->starts_at)->format('Y-m-d\TH:i') }}"></label>
                    <label class="sg-field"><span>Ставка</span><input name="market" value="{{ $bet->market }}" required></label>
                    <label class="sg-field"><span>Свой коэффициент</span><input type="number" name="selected_odds" min="1.001" step="0.001" value="{{ $bet->selected_odds }}"></label>
                    <label class="sg-field"><span>Оценка качества, %</span><input type="number" name="ai_score" min="1" max="100" value="{{ $bet->ai_score }}" required></label>
                    <label class="sg-field sg-field-wide"><span>Свой текст публикации (необязательно)</span><textarea name="publication_text" rows="4" placeholder="Если поле пустое, бот сформирует сообщение автоматически">{{ $bet->publication_text }}</textarea></label>
                </div>
                <button class="sg-button sg-button-secondary sg-button-small" type="submit">Сохранить изменения</button>
            </form>
        </details>
        @endif
    @else
        <div class="bet-published-summary"><span>Коэффициент: <b>{{ number_format((float) $bet->selected_odds, 2) }}</b></span><span>Источник: <b>{{ $bet->selected_odds_source ?: '—' }}</b></span>@if($bet->result_sent_at)<span>Результат отправлен {{ $bet->result_sent_at->format('d.m.Y H:i') }}</span>@endif</div>
        <div class="sg-notice sg-notice-warning bet-manual-result-note">Результат устанавливается администратором вручную по официальному источнику. Автоматическое определение отключено.</div>
        <details class="bet-details"><summary>Редактировать ставку и результат</summary>
            <form class="sg-form bet-edit-form" method="POST" action="{{ route('admin.betting.update', $bet) }}">@csrf @method('PUT')
                <div class="sg-form-grid">
                    <label class="sg-field"><span>Событие</span><input name="event_name" value="{{ $bet->event_name }}" required></label>
                    <label class="sg-field"><span>Турнир</span><input name="tournament" value="{{ $bet->tournament }}"></label>
                    <label class="sg-field"><span>Дата и время</span><input type="datetime-local" name="starts_at" value="{{ optional($bet->starts_at)->format('Y-m-d\TH:i') }}"></label>
                    <label class="sg-field"><span>Ставка</span><input name="market" value="{{ $bet->market }}" required></label>
                    <label class="sg-field"><span>Коэффициент</span><input type="number" name="selected_odds" min="1.001" step="0.001" value="{{ $bet->selected_odds }}"></label>
                    <label class="sg-field"><span>Оценка качества, %</span><input type="number" name="ai_score" min="1" max="100" value="{{ $bet->ai_score }}" required></label>
                    <label class="sg-field sg-field-wide"><span>Текст публикации</span><textarea name="publication_text" rows="4">{{ $bet->publication_text }}</textarea></label>
                    <label class="sg-field"><span>Результат</span><select name="result"><option value="pending" @selected($bet->result === 'pending')>Ожидает</option><option value="win" @selected($bet->result === 'win')>Выигрыш</option><option value="loss" @selected($bet->result === 'loss')>Проигрыш</option><option value="refund" @selected($bet->result === 'refund')>Возврат</option></select></label>
                    <label class="sg-field"><span>Комментарий к результату</span><input name="result_note" value="{{ $bet->result_note }}"></label>
                </div>
                <button class="sg-button sg-button-secondary sg-button-small" type="submit">Сохранить изменения</button>
            </form>
            @if($bet->result_publication_status === \App\Models\Bet::RESULT_PUBLICATION_UNCERTAIN)
                <div class="sg-notice sg-notice-warning bet-manual-result-note">
                    <strong>Telegram не подтвердил результат.</strong> {{ $bet->result_publication_error }} Сначала проверьте канал; автоматический повтор заблокирован.
                </div>
                <div class="bet-card-actions">
                    <form method="POST" action="{{ route('admin.betting.resolve-result-publication', $bet) }}">
                        @csrf
                        <input type="hidden" name="resolution" value="sent">
                        <label><span>ID сообщения с результатом</span><input type="number" name="telegram_message_id" min="1" value="{{ $bet->result_message_id }}" required></label>
                        <button class="sg-button sg-button-primary sg-button-small" type="submit">Подтвердить отправку</button>
                    </form>
                    <form method="POST" action="{{ route('admin.betting.resolve-result-publication', $bet) }}" data-confirm="Вы проверили канал и уверены, что результат не опубликован?">
                        @csrf
                        <input type="hidden" name="resolution" value="retry">
                        <button class="sg-button sg-button-secondary sg-button-small" type="submit">Разрешить повтор</button>
                    </form>
                </div>
            @elseif(in_array($bet->result, ['win', 'loss', 'refund'], true) && !$bet->result_sent_at)
                <form class="bet-result-form" method="POST" action="{{ route('admin.betting.send-result', $bet) }}">@csrf
                    <label class="sg-field"><span>Канал результата</span><select name="publication_bot_id"><option value="">Канал ставки</option>@foreach($bots as $bot)<option value="{{ $bot->id }}">{{ $bot->group_name }}</option>@endforeach</select></label>
                    <label class="sg-field"><span>Свой текст (необязательно)</span><textarea name="text" rows="3" placeholder="Если поле пустое, бот сформирует сообщение автоматически"></textarea></label>
                    <button class="sg-button sg-button-primary sg-button-small" type="submit">➤ Отправить результат</button>
                </form>
            @endif
        </details>
    @endif
</article>
