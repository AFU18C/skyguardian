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
    <form class="panel-body settings-form" @submit.prevent>
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
                </select>
            </label>

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
