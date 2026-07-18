@extends('layouts.admin')

@section('title', 'Настройки воздушной тревоги — SkyGuardian')
@section('section', 'Воздушная тревога')
@section('heading', 'Настройки бота')

@section('content')
    <section class="panel alert-bot-panel">
        @if (session('success'))
            <div class="alert-message alert-success" role="status">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="alert-message alert-error" role="alert">{{ session('error') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert-message alert-error" role="alert">{{ $errors->first() }}</div>
        @endif

        @if ($maskedToken)
            <div class="saved-token-card">
                <div class="saved-token-copy">
                    <span class="token-status"><span class="token-status-dot"></span>Токен сохранён</span>
                    <code>{{ $maskedToken }}</code>
                </div>

                <form method="POST" action="{{ route('alerts.settings.token.destroy') }}" onsubmit="return confirm('Удалить сохранённый токен? Бот будет автоматически выключен.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="button button-danger button-compact">Удалить токен</button>
                </form>
            </div>
        @endif

        <form class="settings-form" method="POST" action="{{ route('alerts.settings.update') }}">
            @csrf

            <div class="form-field">
                <label for="telegram_bot_token">{{ $maskedToken ? 'Добавить новый токен' : 'Telegram Bot Token' }}</label>
                <input
                    id="telegram_bot_token"
                    type="password"
                    name="telegram_bot_token"
                    autocomplete="new-password"
                    placeholder="{{ $maskedToken ? 'Введите новый токен для замены текущего' : 'Введите токен от BotFather' }}"
                >
                <small>
                    @if ($maskedToken)
                        Оставьте поле пустым, чтобы сохранить текущий токен. Новый токен заменит его после сохранения.
                    @else
                        Токен будет храниться в базе данных в зашифрованном виде.
                    @endif
                </small>
            </div>

            <label class="toggle-row" for="is_enabled">
                <span class="toggle-copy">
                    <strong>Включить бота</strong>
                    <small>Отправка тревог будет разрешена после включения.</small>
                </span>
                <input
                    id="is_enabled"
                    type="checkbox"
                    name="is_enabled"
                    value="1"
                    @checked(old('is_enabled', $settings?->is_enabled ?? false))
                >
            </label>

            <div class="form-actions settings-actions">
                <button type="submit" class="button button-primary">Сохранить настройки</button>
                <button type="submit" class="button button-secondary" formaction="{{ route('alerts.settings.test') }}">
                    Проверить подключение
                </button>
            </div>
        </form>
    </section>
@endsection
