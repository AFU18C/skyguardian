@php
    $isEdit = isset($source) && $source;
    $context = $isEdit ? 'source-'.$source->id : 'source-create';
    $useOld = old('form_context') === $context;
    $sourceNamePlaceholder = $type === \App\Models\Source::TYPE_NEWS
        ? 'Например: Новости региона'
        : 'Например: Канал воздушных тревог';
    $rulesData = $useOld
        ? old('rules', [])
        : ($isEdit
            ? $source->rules->map(fn ($rule) => [
                'key' => $rule->key,
                'value' => data_get($rule->value, 'value', ''),
                'is_active' => $rule->is_active,
                'priority' => $rule->priority,
            ])->values()->all()
            : []);
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

    <section class="sg-rules" data-rule-repeater>
        <div class="sg-section-heading">
            <div>
                <h3>Правила источника</h3>
                <p>Правила сохраняются в таблице source_rules и применяются обработчиком.</p>
            </div>
            <button class="sg-button sg-button-secondary sg-button-small" type="button" data-add-rule>Добавить правило</button>
        </div>

        <div class="sg-rule-list" data-rule-list>
            @foreach ($rulesData as $index => $rule)
                <div class="sg-rule-row" data-rule-row>
                    <label class="sg-field">
                        <span>Ключ <b>*</b></span>
                        <input type="text" name="rules[{{ $index }}][key]" value="{{ $rule['key'] ?? '' }}" maxlength="64" required>
                    </label>
                    <label class="sg-field sg-rule-value">
                        <span>Значение</span>
                        <input type="text" name="rules[{{ $index }}][value]" value="{{ $rule['value'] ?? '' }}" maxlength="5000">
                    </label>
                    <label class="sg-field sg-rule-priority">
                        <span>Приоритет</span>
                        <input type="number" name="rules[{{ $index }}][priority]" value="{{ $rule['priority'] ?? (($index + 1) * 100) }}" min="0" max="10000">
                    </label>
                    <label class="sg-check sg-rule-active">
                        <input type="hidden" name="rules[{{ $index }}][is_active]" value="0">
                        <input type="checkbox" name="rules[{{ $index }}][is_active]" value="1" @checked((bool) ($rule['is_active'] ?? true))>
                        <span>Активно</span>
                    </label>
                    <button class="sg-icon-button sg-rule-remove" type="button" data-remove-rule aria-label="Удалить правило">×</button>
                </div>
            @endforeach
        </div>

        <template data-rule-template>
            <div class="sg-rule-row" data-rule-row>
                <label class="sg-field"><span>Ключ <b>*</b></span><input type="text" data-name="key" maxlength="64" required></label>
                <label class="sg-field sg-rule-value"><span>Значение</span><input type="text" data-name="value" maxlength="5000"></label>
                <label class="sg-field sg-rule-priority"><span>Приоритет</span><input type="number" data-name="priority" min="0" max="10000"></label>
                <label class="sg-check sg-rule-active"><input type="hidden" data-name="is_active_hidden" value="0"><input type="checkbox" data-name="is_active" value="1" checked><span>Активно</span></label>
                <button class="sg-icon-button sg-rule-remove" type="button" data-remove-rule aria-label="Удалить правило">×</button>
            </div>
        </template>
    </section>

    @if ($useOld)
        @error('rules')<p class="sg-field-error">{{ $message }}</p>@enderror
        @error('rules.*.key')<p class="sg-field-error">{{ $message }}</p>@enderror
    @endif

    <div class="sg-form-actions">
        <button type="button" class="sg-button sg-button-secondary" data-modal-close>Отмена</button>
        <button type="submit" class="sg-button sg-button-primary" data-submit-button>Сохранить</button>
    </div>
</form>
