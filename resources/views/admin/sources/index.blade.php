@php
    $routePrefix = $type === \App\Models\Source::TYPE_NEWS ? 'admin.news' : 'admin.air-alert';
    $emptyText = $type === \App\Models\Source::TYPE_NEWS
        ? 'Источники новостей ещё не добавлены.'
        : 'Источники воздушной тревоги ещё не добавлены.';
    $reservedRuleKeys = ['copy_mode', 'strip_links', 'strip_hashtags', 'strip_mentions', 'remove_phrases', 'footer_html'];
@endphp

<x-layouts.admin :title="$title">
    <x-slot:description>
        {{ $type === \App\Models\Source::TYPE_NEWS
            ? 'Управление Telegram-источниками новостей.'
            : 'Управление Telegram-источниками воздушной тревоги.' }}
    </x-slot:description>

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
                    $additionalRulesCount = $source->rules->reject(fn ($rule) => in_array($rule->key, $reservedRuleKeys, true))->count();
                @endphp
                <article class="sg-record-card">
                    <div class="sg-record-card-top">
                        <div class="sg-record-icon">{{ $type === 'news' ? '▤' : '▲' }}</div>
                        <div class="sg-record-title">
                            <h2>{{ $source->name }}</h2>
                            <p>{{ $source->source_peer }}</p>
                        </div>
                        <x-status-badge :status="$source->is_active ? $source->status : 'disabled'" />
                    </div>

                    <dl class="sg-record-data">
                        <div><dt>Назначение</dt><dd>{{ $source->destination_peer ?: 'Не задано' }}</dd></div>
                        <div><dt>Технический аккаунт</dt><dd>{{ $source->technicalAccount?->name ?: 'Не выбран' }}</dd></div>
                        <div><dt>Интервал</dt><dd>{{ $source->check_interval }} {{ match ($source->check_interval_unit) { 'minutes' => 'мин.', 'hours' => 'ч.', default => 'сек.' } }}</dd></div>
                        <div><dt>Режим копирования</dt><dd>{{ $copyMode === 'text_only' ? 'Только текст' : 'Оригинал с медиа' }}</dd></div>
                        <div><dt>Доп. правила</dt><dd>{{ $additionalRulesCount }}</dd></div>
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
                </article>
            @endforeach
        </section>

        <div class="sg-pagination">{{ $sources->links() }}</div>
    @endif

    @push('modals')
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
        <div data-open-modal-on-load="{{ old('form_context') === 'source-create' ? 'source-create' : 'source-edit-'.str_replace('source-', '', old('form_context')) }}"></div>
    @endif
</x-layouts.admin>
