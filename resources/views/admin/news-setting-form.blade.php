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

@if($errors->any())
    <x-ui.alert>{{ $errors->first() }}</x-ui.alert>
@endif

<section class="panel settings-form-card">
    <form
        class="panel-body settings-form"
        method="POST"
        action="{{ $editing ? route('news.settings.update', $account) : route('news.settings.store') }}"
        x-data="{ loginMethod: @js(old('login_method', $account?->login_method ?? 'phone')) }"
    >
        @csrf
        @if($editing)
            @method('PUT')
        @endif

        <div class="settings-form-grid">
            <label class="field settings-form-wide">
                <span class="field-label">Название API</span>
                <input class="input" name="name" type="text" value="{{ old('name', $account?->name) }}" placeholder="Основной аккаунт" autocomplete="off" required>
            </label>

            <label class="field">
                <span class="field-label">API ID</span>
                <input class="input" name="api_id" type="text" value="{{ old('api_id', $account?->api_id) }}" inputmode="numeric" autocomplete="off" required>
            </label>

            <label class="field">
                <span class="field-label">API Hash</span>
                <input class="input" name="api_hash" type="text" autocomplete="off" @required(! $editing)>
                @if($editing)
                    <span class="field-hint">Оставьте пустым, чтобы не менять API Hash.</span>
                @endif
            </label>

            <label class="field">
                <span class="field-label">Способ входа</span>
                <select class="input" name="login_method" x-model="loginMethod" required>
                    <option value="phone">Телефон</option>
                    <option value="qr">QR-код</option>
                </select>
            </label>

            <label class="field" x-show="loginMethod === 'phone'">
                <span class="field-label">Номер телефона</span>
                <input class="input" name="phone" type="tel" value="{{ old('phone', $account?->phone) }}" placeholder="+380..." autocomplete="off">
            </label>

            <div class="field" x-cloak x-show="loginMethod === 'qr'">
                <span class="field-label">QR-код</span>
                <div class="qr-placeholder">
                    <x-icon name="settings" :size="24"/>
                    <span>QR-код появится здесь после сохранения</span>
                </div>
            </div>
        </div>

        <div class="settings-form-actions">
            <x-ui.button type="submit" variant="primary">
                {{ $editing ? 'Сохранить' : 'Добавить' }}
            </x-ui.button>
        </div>
    </form>

    @if($editing)
        <form class="danger-form" method="POST" action="{{ route('news.settings.destroy', $account) }}" onsubmit="return confirm('Удалить эту настройку?')">
            @csrf
            @method('DELETE')
            <x-ui.button type="submit" variant="danger">Удалить</x-ui.button>
        </form>
    @endif
</section>
@endsection
