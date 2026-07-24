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
        <h1>{{ $editing ? 'Редактировать Telegram App' : 'Добавить Telegram App' }}</h1>
        <p>Укажите API ID и API Hash, созданные на my.telegram.org.</p>
    </div>
</div>

@if($errors->any())
    <x-ui.alert>{{ $errors->first() }}</x-ui.alert>
@endif

@if(session('status'))
    <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
@endif

<section class="panel settings-form-card">
    <form
        id="telegram-app-form"
        class="panel-body settings-form"
        method="POST"
        action="{{ $editing ? route('news.settings.update', $telegramApp) : route('news.settings.store') }}"
    >
        @csrf
        @if($editing)
            @method('PUT')
        @endif

        <div class="settings-form-grid">
            <label class="field settings-form-wide">
                <span class="field-label">Название Telegram App *</span>
                <input class="input" name="name" type="text" value="{{ old('name', $telegramApp?->name) }}" placeholder="Например: Новости — основной API" autocomplete="off" required>
            </label>

            <label class="field">
                <span class="field-label">API ID *</span>
                <input class="input" name="api_id" type="text" value="{{ old('api_id', $telegramApp?->api_id) }}" inputmode="numeric" autocomplete="off" required>
            </label>

            <label class="field">
                <span class="field-label">API Hash *</span>
                <input class="input" name="api_hash" type="text" autocomplete="off" @required(! $editing)>
                @if($editing)
                    <span class="field-hint">Оставьте пустым, чтобы не менять API Hash.</span>
                @endif
            </label>
        </div>
    </form>

    <div class="settings-form-actions panel-form-actions">
        <x-ui.button type="submit" variant="primary" form="telegram-app-form">
            {{ $editing ? 'Сохранить' : 'Продолжить' }}
        </x-ui.button>

        @if($editing)
            <x-ui.button variant="ghost" :href="route('news.accounts.create', $telegramApp)">
                <x-icon name="plus" :size="14"/>
                Добавить техаккаунт
            </x-ui.button>
        @endif
    </div>

    @if($editing)
        <form class="danger-form" method="POST" action="{{ route('news.settings.destroy', $telegramApp) }}" onsubmit="return confirm('Удалить Telegram App? Сначала необходимо удалить его техаккаунты.')">
            @csrf
            @method('DELETE')
            <x-ui.button type="submit" variant="danger">Удалить Telegram App</x-ui.button>
        </form>
    @endif
</section>

@if($editing)
    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title">Технические аккаунты</div>
                <div class="panel-copy">Подключите хотя бы один аккаунт, чтобы добавлять каналы данных.</div>
            </div>
        </div>
        <div class="panel-body compact-list">
            @forelse($telegramApp->accounts as $account)
                <a class="compact-list-row" href="{{ route('news.accounts.edit', [$telegramApp, $account]) }}">
                    <span><x-icon name="account" :size="18"/></span>
                    <strong>{{ $account->name }}</strong>
                    <small>{{ $account->telegram_name ?: 'Не подключён' }}</small>
                    <x-icon name="chevron" :size="16"/>
                </a>
            @empty
                <div class="technical-account-empty">
                    Техаккаунты ещё не добавлены. Нажмите «Добавить техаккаунт».
                </div>
            @endforelse
        </div>
    </section>
@endif
@endsection
