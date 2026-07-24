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

<section class="panel settings-form-card">
    <form
        class="panel-body settings-form"
        x-data="{ appendCustomText: false }"
        @submit.prevent
    >
        <div class="settings-form-grid">
            <label class="field settings-form-wide">
                <span class="field-label">Название *</span>
                <input class="input" type="text" placeholder="Например: Новости города" autocomplete="off" required>
            </label>

            <label class="field settings-form-wide">
                <span class="field-label">Канал или группа — источник сообщений *</span>
                <input class="input" type="text" placeholder="@source_channel или ссылка" autocomplete="off" required>
            </label>

            <label class="field settings-form-wide">
                <span class="field-label">Технический аккаунт</span>
                <select class="input">
                    <option value="">Нет подключённых аккаунтов</option>
                </select>
            </label>

            <label class="field settings-form-wide">
                <span class="field-label">Канал или группа для публикации *</span>
                <input class="input" type="text" placeholder="@destination_channel или ссылка" autocomplete="off" required>
            </label>

            <label class="field settings-form-wide">
                <span class="field-label">Формат публикации *</span>
                <select class="input" required>
                    <option value="">Выберите формат публикации</option>
                    <option value="original">Оригинал</option>
                    <option value="text">Только текст</option>
                </select>
            </label>

            <div class="settings-form-wide form-section">
                <div class="form-section-heading">
                    <h2>Фильтры сообщений</h2>
                    <p>Укажите слова через запятую. Поля можно оставить пустыми.</p>
                </div>

                <div class="settings-form-grid">
                    <label class="field">
                        <span class="field-label">Ключевые слова</span>
                        <textarea
                            class="input textarea"
                            rows="4"
                            placeholder="Например: Запорожье, событие, новости"
                        ></textarea>
                        <span class="field-hint">Будут обрабатываться сообщения, содержащие хотя бы одно слово.</span>
                    </label>

                    <label class="field">
                        <span class="field-label">Стоп-слова</span>
                        <textarea
                            class="input textarea"
                            rows="4"
                            placeholder="Например: реклама, розыгрыш"
                        ></textarea>
                        <span class="field-hint">Сообщения с этими словами не будут публиковаться.</span>
                    </label>
                </div>
            </div>

            <div class="settings-form-wide form-section">
                <label class="custom-text-toggle">
                    <input
                        class="checkbox"
                        type="checkbox"
                        x-model="appendCustomText"
                    >
                    <span>
                        <strong>Добавить свой текст в конце сообщения</strong>
                        <small>Если галочка выключена, дополнительный текст не добавляется.</small>
                    </span>
                </label>

                <div
                    x-cloak
                    x-show="appendCustomText"
                    x-transition
                    class="field custom-text-editor"
                >
                    <span class="field-label">Свой текст</span>
                    <div class="editor-shell">
                        <div class="editor-toolbar" aria-label="Панель форматирования">
                            <button type="button" title="Жирный"><strong>B</strong></button>
                            <button type="button" title="Курсив"><em>I</em></button>
                            <button type="button" title="Ссылка">Ссылка</button>
                        </div>
                        <textarea
                            class="editor-area"
                            rows="6"
                            placeholder="Введите текст, который будет добавлен в конце скопированного сообщения"
                        ></textarea>
                    </div>
                </div>
            </div>

            <div class="field settings-form-wide">
                <span class="field-label">Частота проверки *</span>
                <div class="data-channel-frequency">
                    <input class="input" type="number" min="3" max="86400" placeholder="От 3 до 86400" required>
                    <select class="input" required>
                        <option value="seconds">Секунды</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="settings-form-actions">
            <x-ui.button type="submit" variant="primary">
                {{ $editing ? 'Сохранить' : 'Добавить' }}
            </x-ui.button>

            @if($editing)
                <x-ui.button type="button" variant="danger">
                    Удалить
                </x-ui.button>
            @endif
        </div>
    </form>
</section>
@endsection
