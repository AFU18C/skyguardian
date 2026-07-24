@extends('layouts.admin')

@section('title', $editing ? 'Редактирование канала данных' : 'Добавление канала данных')
@section('section', 'Новости')
@section('page-title', $editing ? 'Редактирование канала данных' : 'Добавление канала данных')

@section('content')
<div class="content-heading">
    <div>
        <a class="back-link" href="{{ route('news.channels') }}">
            <x-icon name="chevron" :size="14" class="back-link-icon"/>
            Назад к каналам данных
        </a>
        <h1>{{ $editing ? 'Редактировать канал данных' : 'Добавить канал данных' }}</h1>
        <p>Укажите, откуда получать сообщения и куда их публиковать.</p>
    </div>
</div>

@if($errors->any())
    <x-ui.alert>{{ $errors->first() }}</x-ui.alert>
@endif

<section
    class="panel settings-form-card"
    x-data="{
        appendCustomText: @js((bool) old('append_custom_text', $channel?->append_custom_text ?? false)),
        frequencyUnit: @js(old('frequency_unit', $frequencyUnit))
    }"
>
    <form
        id="news-channel-form"
        class="panel-body settings-form"
        method="POST"
        action="{{ $editing ? route('news.channels.update', $channel) : route('news.channels.store') }}"
    >
        @csrf
        @if($editing)
            @method('PUT')
        @endif

        <div class="settings-form-grid">
            <label class="field settings-form-wide">
                <span class="field-label">Название *</span>
                <input class="input" name="name" type="text" value="{{ old('name', $channel?->name) }}" placeholder="Например: Новости города" autocomplete="off" required>
            </label>

            <label class="field settings-form-wide">
                <span class="field-label">Канал или группа — источник сообщений *</span>
                <input class="input" name="identifier" type="text" value="{{ old('identifier', $channel?->identifier) }}" placeholder="@source_channel или ссылка" autocomplete="off" required>
            </label>

            <label class="field settings-form-wide">
                <span class="field-label">Технический аккаунт *</span>
                <select class="input" name="telegram_account_id" required>
                    <option value="">{{ $channel && ! $channel->telegram_account_id ? 'Техаккаунт удалён — выберите новый' : 'Выберите технический аккаунт' }}</option>
                    @foreach($accounts as $accountOption)
                        <option
                            value="{{ $accountOption->id }}"
                            @selected((string) old('telegram_account_id', $channel?->telegram_account_id) === (string) $accountOption->id)
                        >
                            {{ $accountOption->name }}
                        </option>
                    @endforeach
                </select>
                <span class="field-hint">Доступны только подключённые и включённые техаккаунты.</span>
            </label>

            <label class="field settings-form-wide">
                <span class="field-label">Канал или группа для публикации *</span>
                <input class="input" name="publication_identifier" type="text" value="{{ old('publication_identifier', $channel?->publication_identifier) }}" placeholder="@destination_channel или ссылка" autocomplete="off" required>
            </label>

            <label class="field settings-form-wide">
                <span class="field-label">Формат публикации *</span>
                <select class="input" name="publication_format" required>
                    <option value="">Выберите формат публикации</option>
                    <option value="original" @selected(old('publication_format', $channel?->publication_format) === 'original')>Оригинал</option>
                    <option value="text" @selected(old('publication_format', $channel?->publication_format) === 'text')>Только текст</option>
                </select>
                <span class="field-hint">В обоих форматах ссылки и хештеги удаляются.</span>
            </label>

            <div class="settings-form-wide form-section">
                <div class="form-section-heading">
                    <h2>Фильтры сообщений</h2>
                    <p>Укажите слова через запятую. Поля можно оставить пустыми.</p>
                </div>

                <div class="settings-form-grid">
                    <label class="field">
                        <span class="field-label">Ключевые слова</span>
                        <textarea class="input textarea" name="keywords" rows="4" placeholder="Например: Запорожье, событие, новости">{{ old('keywords', $channel?->keywords) }}</textarea>
                        <span class="field-hint">Будут обрабатываться сообщения, содержащие хотя бы одно слово.</span>
                    </label>

                    <label class="field">
                        <span class="field-label">Стоп-слова</span>
                        <textarea class="input textarea" name="stop_words" rows="4" placeholder="Например: реклама, розыгрыш">{{ old('stop_words', $channel?->stop_words) }}</textarea>
                        <span class="field-hint">Сообщения с этими словами не будут публиковаться.</span>
                    </label>
                </div>
            </div>

            <div class="settings-form-wide form-section">
                <label class="custom-text-toggle">
                    <input type="hidden" name="append_custom_text" value="0">
                    <input class="checkbox" name="append_custom_text" value="1" type="checkbox" x-model="appendCustomText">
                    <span>
                        <strong>Добавить свой текст в конце сообщения</strong>
                        <small>Если галочка выключена, дополнительный текст не добавляется.</small>
                    </span>
                </label>

                <div x-cloak x-show="appendCustomText" x-transition class="field custom-text-editor">
                    <span class="field-label">Свой текст</span>
                    <div class="editor-shell">
                        <textarea
                            class="editor-area"
                            name="custom_text"
                            rows="6"
                            placeholder="Введите текст, который будет добавлен в конце скопированного сообщения"
                        >{{ old('custom_text', $channel?->custom_text) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="field settings-form-wide">
                <span class="field-label">Частота проверки *</span>
                <div class="data-channel-frequency">
                    <input
                        class="input"
                        name="frequency_value"
                        type="number"
                        value="{{ old('frequency_value', $frequencyValue) }}"
                        :min="frequencyUnit === 'seconds' ? 3 : 1"
                        :max="frequencyUnit === 'hours' ? 12 : (frequencyUnit === 'minutes' ? 720 : 43200)"
                        required
                    >
                    <select class="input" name="frequency_unit" x-model="frequencyUnit" required>
                        <option value="seconds">Секунды</option>
                        <option value="minutes">Минуты</option>
                        <option value="hours">Часы</option>
                    </select>
                </div>
                <span class="field-hint">Допустимый интервал — от 3 секунд до 12 часов.</span>
                <span class="field-hint">Перед сохранением система проверит чтение источника и право публикации. Старые сообщения при первом запуске публиковаться не будут.</span>
            </div>
        </div>
    </form>

    <div class="settings-form-actions panel-form-actions">
        <x-ui.button type="submit" variant="primary" form="news-channel-form">
            {{ $editing ? 'Сохранить' : 'Добавить' }}
        </x-ui.button>

        @if($editing)
            <form method="POST" action="{{ route('news.channels.destroy', $channel) }}" onsubmit="return confirm('Удалить этот канал данных?')">
                @csrf
                @method('DELETE')
                <x-ui.button type="submit" variant="danger">Удалить</x-ui.button>
            </form>
        @endif
    </div>
</section>
@endsection
