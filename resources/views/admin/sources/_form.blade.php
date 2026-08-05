@php
    $isEdit = isset($source) && $source;
    $context = $isEdit ? 'source-'.$source->id : 'source-create';
    $useOld = old('form_context') === $context;
    $sourceNamePlaceholder = $type === \App\Models\Source::TYPE_NEWS
        ? 'Например: Новости региона'
        : 'Например: Канал воздушных тревог';
    $storedRules = $isEdit ? $source->rules->keyBy('key') : collect();
    $setting = static function (string $key, mixed $default = null) use ($useOld, $isEdit, $storedRules): mixed {
        if ($useOld) {
            return old($key, $default);
        }

        return $isEdit
            ? data_get($storedRules->get($key)?->value, 'value', $default)
            : $default;
    };
    $copyMode = (string) $setting('copy_mode', 'original');
    $footerHtml = (string) $setting('footer_html', '');
    $blockedKeywordsEnabled = $useOld
        ? (bool) old('blocked_keywords_enabled', false)
        : ($isEdit ? (bool) $storedRules->get('blocked_keywords')?->is_active : false);
@endphp

<form method="POST" action="{{ $action }}" class="sg-form" data-dirty-form data-source-form>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <input type="hidden" name="form_context" value="{{ $context }}">

    <div class="sg-form-grid">
        <label class="sg-field sg-field-wide">
            <span>Название источника <b>*</b></span>
            <input type="text" name="name" maxlength="255" required
                   value="{{ $useOld ? old('name') : ($source->name ?? '') }}"
                   placeholder="{{ $sourceNamePlaceholder }}">
            @if ($useOld) @error('name')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>

        <label class="sg-field">
            <span>Исходный Telegram-канал или группа <b>*</b></span>
            <input type="text" name="source_peer" maxlength="255" required
                   value="{{ $useOld ? old('source_peer') : ($source->source_peer ?? '') }}"
                   placeholder="@channel или ID">
            @if ($useOld) @error('source_peer')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>

        <label class="sg-field">
            <span>Канал назначения</span>
            <input type="text" name="destination_peer" maxlength="255"
                   value="{{ $useOld ? old('destination_peer') : ($source->destination_peer ?? '') }}"
                   placeholder="@destination или ID">
            @if ($useOld) @error('destination_peer')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>

        <label class="sg-field sg-field-wide">
            <span>Технический аккаунт</span>
            <select name="technical_account_id">
                <option value="">Не выбран</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" @selected((string) ($useOld ? old('technical_account_id') : ($source->technical_account_id ?? '')) === (string) $account->id)>
                        {{ $account->name }} — {{ $account->telegramApi?->name ?? 'API не определён' }}
                    </option>
                @endforeach
            </select>
            @if ($useOld) @error('technical_account_id')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>

        <label class="sg-field">
            <span>Интервал проверки <b>*</b></span>
            <input type="number" name="check_interval" min="1" max="86400" required
                   value="{{ $useOld ? old('check_interval', 60) : ($source->check_interval ?? 60) }}">
            @if ($useOld) @error('check_interval')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>

        <label class="sg-field">
            <span>Единица интервала <b>*</b></span>
            @php($unit = $useOld ? old('check_interval_unit', 'seconds') : ($source->check_interval_unit ?? 'seconds'))
            <select name="check_interval_unit" required>
                <option value="seconds" @selected($unit === 'seconds')>Секунды</option>
                <option value="minutes" @selected($unit === 'minutes')>Минуты</option>
                <option value="hours" @selected($unit === 'hours')>Часы</option>
            </select>
            @if ($useOld) @error('check_interval_unit')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>
    </div>

    <label class="sg-switch-row">
        <span>
            <strong>Источник активен</strong>
            <small>Автоматическая обработка выполняется по установленному интервалу.</small>
        </span>
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked((bool) ($useOld ? old('is_active') : ($source->is_active ?? false)))>
    </label>

    <section class="sg-copy-settings">
        <div class="sg-section-heading">
            <div>
                <p class="sg-eyebrow">Публикация</p>
                <h3>Копирование сообщений</h3>
                <p>Сообщение публикуется от имени канала назначения без отметки «Переслано».</p>
            </div>
        </div>

        <div class="sg-choice-grid sg-copy-mode-grid">
            <label class="sg-choice-card">
                <input type="radio" name="copy_mode" value="original" @checked($copyMode === 'original')>
                <span class="sg-choice-icon">▣</span>
                <span><strong>Оригинал</strong><small>Копировать текст, фото, видео, документы и альбомы.</small></span>
            </label>
            <label class="sg-choice-card">
                <input type="radio" name="copy_mode" value="text_only" @checked($copyMode === 'text_only')>
                <span class="sg-choice-icon">T</span>
                <span><strong>Только текст</strong><small>Публиковать текст и подпись без медиафайлов.</small></span>
            </label>
        </div>
        @if ($useOld) @error('copy_mode')<p class="sg-field-error">{{ $message }}</p>@enderror @endif

        <div class="sg-copy-toggle-grid">
            <label class="sg-switch-row">
                <span><strong>Удалять ссылки</strong><small>HTTP-ссылки и ссылки Telegram не попадут в публикацию.</small></span>
                <input type="hidden" name="strip_links" value="0">
                <input type="checkbox" name="strip_links" value="1" @checked((bool) $setting('strip_links', false))>
            </label>
            <label class="sg-switch-row">
                <span><strong>Удалять хештеги</strong><small>Из текста будут удалены слова, начинающиеся с #.</small></span>
                <input type="hidden" name="strip_hashtags" value="0">
                <input type="checkbox" name="strip_hashtags" value="1" @checked((bool) $setting('strip_hashtags', false))>
            </label>
            <label class="sg-switch-row">
                <span><strong>Удалять @упоминания</strong><small>Из текста будут удалены Telegram-username.</small></span>
                <input type="hidden" name="strip_mentions" value="0">
                <input type="checkbox" name="strip_mentions" value="1" @checked((bool) $setting('strip_mentions', false))>
            </label>
        </div>

        <label class="sg-field">
            <span>Удалить из текста слова или фразы</span>
            <textarea name="remove_phrases" maxlength="10000" placeholder="Каждое слово или фраза с новой строки">{{ $setting('remove_phrases', '') }}</textarea>
            <small>Совпадения удаляются без учёта регистра. Каждую фразу указывай с новой строки.</small>
            @if ($useOld) @error('remove_phrases')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>

        <div class="sg-field">
            <span>Свой текст внизу публикации</span>
            <div class="sg-rich-editor" data-rich-editor>
                <div class="sg-rich-editor-toolbar" role="toolbar" aria-label="Форматирование текста">
                    <button type="button" data-editor-command="bold" title="Жирный"><b>B</b></button>
                    <button type="button" data-editor-command="italic" title="Курсив"><i>I</i></button>
                    <button type="button" data-editor-command="underline" title="Подчёркнутый"><u>U</u></button>
                    <button type="button" data-editor-command="strikeThrough" title="Зачёркнутый"><s>S</s></button>
                    <button type="button" data-editor-link title="Добавить ссылку">Ссылка</button>
                    <button type="button" data-editor-command="removeFormat" title="Очистить форматирование">Очистить</button>
                </div>
                <div class="sg-rich-editor-surface" contenteditable="true" data-editor-surface data-placeholder="Например: Подписаться на канал…"></div>
                <textarea name="footer_html" data-editor-input hidden>{{ $footerHtml }}</textarea>
            </div>
            <small>Этот блок добавляется после скопированного текста. Поддерживаются жирный, курсив, подчёркивание, зачёркивание и ссылки.</small>
            @if ($useOld) @error('footer_html')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </div>

        @if ($isEdit)
            <label class="sg-switch-row sg-reset-cursor-row">
                <span>
                    <strong>Начать только с новых сообщений</strong>
                    <small>После сохранения текущая история будет пропущена, а следующая публикация начнётся с новых сообщений.</small>
                </span>
                <input type="hidden" name="reset_cursor" value="0">
                <input type="checkbox" name="reset_cursor" value="1" @checked((bool) ($useOld ? old('reset_cursor') : false))>
            </label>
        @endif
    </section>

    @include('admin.sources._map_button_settings')

    <section class="sg-rules">
        <div class="sg-section-heading">
            <div>
                <h3>Запрещённые слова</h3>
                <p>Не публиковать пост целиком, если в его тексте найдено заданное слово или фраза.</p>
            </div>
        </div>

        <label class="sg-switch-row">
            <span><strong>Включить фильтр</strong><small>Выключенный фильтр не влияет на копирование сообщений.</small></span>
            <input type="hidden" name="blocked_keywords_enabled" value="0">
            <input type="checkbox" name="blocked_keywords_enabled" value="1" @checked($blockedKeywordsEnabled)>
        </label>

        <label class="sg-field">
            <span>Слова и фразы</span>
            <textarea name="blocked_keywords" maxlength="10000" placeholder="Например:&#10;казино&#10;ставки на спорт">{{ $setting('blocked_keywords', '') }}</textarea>
            <small>Каждое слово или фразу указывай с новой строки. Регистр не учитывается.</small>
            @if ($useOld) @error('blocked_keywords')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>
    </section>

    @if ($useOld)
        @error('blocked_keywords_enabled')<p class="sg-field-error">{{ $message }}</p>@enderror
    @endif

    <div class="sg-form-actions">
        <button type="button" class="sg-button sg-button-secondary" data-modal-close>Отмена</button>
        <button type="submit" class="sg-button sg-button-primary" data-submit-button>Сохранить</button>
    </div>
</form>
