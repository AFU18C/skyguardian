@extends('layouts.admin')

@section('title', $editing ? 'Редактирование настройки' : 'Добавление настройки')
@section('section', 'Новости')
@section('page-title', $editing ? 'Редактирование настройки' : 'Добавление настройки')

@section('content')
<div class="content-heading">
    <div>
        <a class="back-link" href="{{ route('news.settings') }}">
            <x-icon name="chevron" :size="14" class="back-link-icon"/>
            Назад к настройкам
        </a>
        <h1>{{ $editing ? 'Редактировать Telegram API и технический аккаунт' : 'Добавить Telegram API и технический аккаунт' }}</h1>
        <p>Заполните данные подключения для раздела «Новости».</p>
    </div>
</div>

<section class="panel settings-form-card">
    <form class="panel-body settings-form" x-data="{ loginMethod: 'phone' }" @submit.prevent>
        <div class="settings-form-grid">
            <label class="field settings-form-wide">
                <span class="field-label">Название API</span>
                <input class="input" type="text" placeholder="Основной аккаунт" autocomplete="off">
            </label>

            <label class="field">
                <span class="field-label">API ID</span>
                <input class="input" type="text" inputmode="numeric" autocomplete="off">
            </label>

            <label class="field">
                <span class="field-label">API Hash</span>
                <input class="input" type="text" autocomplete="off">
            </label>

            <label class="field">
                <span class="field-label">Способ входа</span>
                <select class="input" x-model="loginMethod">
                    <option value="phone">Телефон</option>
                    <option value="qr">QR-код</option>
                </select>
            </label>

            <label class="field" x-show="loginMethod === 'phone'">
                <span class="field-label">Номер телефона</span>
                <input class="input" type="tel" placeholder="+380..." autocomplete="off">
            </label>

            <div class="field" x-cloak x-show="loginMethod === 'qr'">
                <span class="field-label">QR-код</span>
                <div class="qr-placeholder">
                    <x-icon name="settings" :size="24"/>
                    <span>QR-код появится здесь после подключения</span>
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
