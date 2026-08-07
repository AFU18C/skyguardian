@php
    $routePrefix = $type === \App\Models\Source::TYPE_NEWS ? 'admin.news' : 'admin.air-alert';
    $emptyText = $type === \App\Models\Source::TYPE_NEWS
        ? 'Источники новостей ещё не добавлены.'
        : 'Источники воздушной тревоги ещё не добавлены.';
    $pollingModalId = 'source-polling-settings-'.$type;
    $pollingSectionName = $type === \App\Models\Source::TYPE_NEWS ? 'новостей' : 'воздушных тревог';
@endphp

<x-layouts.admin :title="$title">
    <x-slot:description>
        {{ $type === \App\Models\Source::TYPE_NEWS
            ? 'Управление Telegram-источниками новостей.'
            : 'Управление Telegram-источниками воздушной тревоги.' }}
    </x-slot:description>

    <x-slot:titleActions>
        <button
            class="sg-icon-button sg-page-settings-button"
            type="button"
            data-modal-open="{{ $pollingModalId }}"
            aria-label="Настройки автоматической проверки {{ $pollingSectionName }}"
            title="Настройки автоматической проверки"
        >⚙</button>
    </x-slot:titleActions>

    <x-slot:actions>
        <button class="sg-button sg-button-primary" type="button" data-modal-open="source-create" @disabled($sourceLimitReached)>
            + Добавить источник
        </button>
    </x-slot:actions>

    @if ($sourceLimitReached)
        <div class="sg-notice sg-notice-warning">
            Достигнут общий лимит: {{ config('skyguardian.limits.sources', 40) }} источников. Существующие записи можно редактировать.
        </div>
    @endif

    @if ($sources->isEmpty())
        <section class="sg-empty-state">
            <div class="sg-empty-symbol">{{ $type === 'news' ? '▤' : '▲' }}</div>
            <h2>{{ $emptyText }}</h2>
            @unless ($sourceLimitReached)
                <button class="sg-button sg-button-primary" type="button" data-modal-open="source-create">Добавить источник</button>
            @endunless
        </section>
    @else
        <section class="sg-card-grid">
            @foreach ($sources as $source)
                @php
                    $copyMode = data_get($source->rules->firstWhere('key', 'copy_mode')?->value, 'value', 'original');
                    $blockedKeywordsRule = $source->rules->firstWhere('key', 'blocked_keywords');
                    $detailsId = 'source-card-details-'.$source->id;
                @endphp
                <article class="sg-record-card sg-collapsible-card" data-collapsible-card>
                    <div class="sg-record-card-top">
                        <div class="sg-record-icon">{{ $type === 'news' ? '▤' : '▲' }}</div>
                        <div class="sg-record-title">
                            <h2>{{ $source->name }}</h2>
                            <p>{{ $source->source_peer }}</p>
                        </div>
                        <div class="sg-card-summary-actions">
                            <x-status-badge :status="$source->is_active ? $source->status : 'disabled'" />
                            <button
                                class="sg-card-toggle"
                                type="button"
                                aria-expanded="false"
                                aria-controls="{{ $detailsId }}"
                                aria-label="Развернуть карточку источника {{ $source->name }}"
                                data-card-toggle
                            ><span aria-hidden="true"></span></button>
                        </div>
                    </div>

                    <div class="sg-card-details" id="{{ $detailsId }}" data-card-details hidden>
                        <dl class="sg-record-data">
                            <div><dt>Назначение</dt><dd>{{ $source->destination_peer ?: 'Не задано' }}</dd></div>
                            <div><dt>Технический аккаунт</dt><dd>{{ $source->technicalAccount?->name ?: 'Не выбран' }}</dd></div>
                            <div><dt>Интервал</dt><dd>{{ $source->check_interval }} {{ match ($source->check_interval_unit) { 'minutes' => 'мин.', 'hours' => 'ч.', default => 'сек.' } }}</dd></div>
                            <div><dt>Режим копирования</dt><dd>{{ $copyMode === 'text_only' ? 'Только текст' : 'Оригинал с медиа' }}</dd></div>
                            <div><dt>Запрещённые слова</dt><dd>{{ $blockedKeywordsRule?->is_active ? 'Включены' : 'Выключены' }}</dd></div>
                            <div><dt>Последняя ручная проверка</dt><dd>{{ $source->last_manual_check_at?->timezone('Europe/Kyiv')->format('d.m.Y H:i') ?? 'Не выполнялась' }}</dd></div>
                            <div><dt>Обновлено</dt><dd>{{ $source->updated_at->timezone('Europe/Kyiv')->format('d.m.Y H:i') }}</dd></div>
                        </dl>

                        @if ($source->last_error)
                            <div class="sg-inline-error" title="{{ $source->last_error }}">{{ \Illuminate\Support\Str::limit($source->last_error, 120) }}</div>
                        @endif

                        <div class="sg-record-actions">
                            <form method="POST" action="{{ route($routePrefix.'.check', $source) }}">
                                @csrf
                                <button class="sg-button sg-button-secondary sg-button-small" type="submit" data-submit-button>Проверить сейчас</button>
                            </form>
                            <button class="sg-button sg-button-primary sg-button-small" type="button" data-modal-open="source-edit-{{ $source->id }}">Открыть</button>
                        </div>
                    </div>
                </article>
            @endforeach
        </section>

        <div class="sg-pagination">{{ $sources->links() }}</div>
    @endif

    @push('modals')
        <x-modal id="{{ $pollingModalId }}" title="Настройки проверки {{ $pollingSectionName }}">
            <form method="POST" action="{{ route($routePrefix.'.polling-settings.update') }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="form_context" value="source-polling-settings">
                <input type="hidden" name="source_type" value="{{ $type }}">

                <label class="sg-switch-row">
                    <span>
                        <strong>Автоматическая проверка источников</strong>
                        <small>Проверять, наступило ли время <code>next_check_at</code>, только для раздела {{ $pollingSectionName }}.</small>
                    </span>
                    <input type="hidden" name="polling_enabled" value="0">
                    <input
                        type="checkbox"
                        name="polling_enabled"
                        value="1"
                        @checked((bool) old('polling_enabled', $pollingSettings['enabled']))
                    >
                </label>

                <div class="sg-field">
                    <label for="source-polling-interval-{{ $type }}">Проверять наступление времени каждые</label>
                    <div class="sg-inline-field">
                        <input
                            id="source-polling-interval-{{ $type }}"
                            type="number"
                            name="polling_interval_value"
                            value="{{ old('polling_interval_value', $pollingSettings['interval_value']) }}"
                            min="1"
                            required
                        >
                        <select name="polling_interval_unit" required>
                            <option value="seconds" @selected(old('polling_interval_unit', $pollingSettings['interval_unit']) === 'seconds')>Секунды</option>
                            <option value="minutes" @selected(old('polling_interval_unit', $pollingSettings['interval_unit']) === 'minutes')>Минуты</option>
                            <option value="hours" @selected(old('polling_interval_unit', $pollingSettings['interval_unit']) === 'hours')>Часы</option>
                        </select>
                    </div>
                    <small>Минимальный интервал — 1 секунда. У каждого источника по-прежнему остаётся собственный интервал проверки.</small>
                    @error('polling_interval_value')<small class="sg-field-error">{{ $message }}</small>@enderror
                    @error('polling_interval_unit')<small class="sg-field-error">{{ $message }}</small>@enderror
                </div>

                <div class="sg-record-actions">
                    <button class="sg-button sg-button-primary" type="submit" data-submit-button>Сохранить</button>
                </div>
            </form>
        </x-modal>

        <x-modal id="source-create" title="Добавить источник" size="xl">
            @include('admin.sources._form', [
                'source' => null,
                'accounts' => $accounts,
                'action' => route($routePrefix.'.store'),
            ])
        </x-modal>

        @foreach ($sources as $source)
            <x-modal id="source-edit-{{ $source->id }}" title="Редактирование источника" size="xl">
                @include('admin.sources._form', [
                    'source' => $source,
                    'accounts' => $accounts,
                    'action' => route($routePrefix.'.update', $source),
                ])

                <div class="sg-danger-zone">
                    <div><strong>Удаление источника</strong><p>Запись и её правила будут удалены без восстановления.</p></div>
                    <form method="POST" action="{{ route($routePrefix.'.destroy', $source) }}" data-confirm="Вы действительно хотите удалить этот источник?">
                        @csrf
                        @method('DELETE')
                        <button class="sg-button sg-button-danger" type="submit">Удалить</button>
                    </form>
                </div>
            </x-modal>
        @endforeach
    @endpush

    @if ($errors->any() && old('form_context'))
        @php
            $modalToOpen = match (old('form_context')) {
                'source-polling-settings' => $pollingModalId,
                'source-create' => 'source-create',
                default => 'source-edit-'.str_replace('source-', '', old('form_context')),
            };
        @endphp
        <div data-open-modal-on-load="{{ $modalToOpen }}"></div>
    @endif
</x-layouts.admin>