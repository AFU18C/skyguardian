@extends('layouts.admin')

@section('title', $editing ? 'Технический аккаунт' : 'Добавление техаккаунта')
@section('section', 'Новости')
@section('page-title', $editing ? 'Технический аккаунт' : 'Добавление техаккаунта')

@section('content')
<div class="content-heading">
    <div>
        <a class="back-link" href="{{ route('news.settings.edit', $telegramApp) }}">
            <x-icon name="chevron" :size="14" class="back-link-icon"/>
            Назад к Telegram App
        </a>
        <h1>{{ $editing ? 'Настроить технический аккаунт' : 'Добавить технический аккаунт' }}</h1>
        <p>{{ $telegramApp->name }} · API ID {{ $telegramApp->api_id }}</p>
    </div>
</div>

@if(session('status'))
    <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
@endif

@if($errors->any())
    <x-ui.alert>{{ $errors->first() }}</x-ui.alert>
@endif

<section
    class="panel settings-form-card"
    x-data="{ loginMethod: @js(old('login_method', $account?->login_method ?? 'phone')) }"
>
    <form
        id="technical-account-form"
        class="panel-body settings-form"
        method="POST"
        action="{{ $editing ? route('news.accounts.update', [$telegramApp, $account]) : route('news.accounts.store', $telegramApp) }}"
    >
        @csrf
        @if($editing)
            @method('PUT')
        @endif

        <div class="settings-form-grid">
            <label class="field settings-form-wide">
                <span class="field-label">Название техаккаунта *</span>
                <input class="input" name="name" type="text" value="{{ old('name', $account?->name) }}" placeholder="Например: Новости — аккаунт 1" required>
            </label>

            <label class="field">
                <span class="field-label">Способ авторизации *</span>
                <select class="input" name="login_method" x-model="loginMethod" required>
                    <option value="phone">Телефон, код и пароль 2FA</option>
                    <option value="qr">QR-код</option>
                </select>
            </label>

            <label class="field" x-show="loginMethod === 'phone'">
                <span class="field-label">Номер телефона *</span>
                <input class="input" name="phone" type="tel" value="{{ old('phone', $account?->phone) }}" placeholder="+380..." autocomplete="tel">
                <span class="field-hint">Номер хранится в зашифрованном виде.</span>
            </label>

            <div class="field" x-cloak x-show="loginMethod === 'qr'">
                <span class="field-label">QR-авторизация</span>
                <div class="qr-placeholder">
                    <x-icon name="account" :size="22"/>
                    <span>После сохранения появится QR-код для сканирования в Telegram.</span>
                </div>
            </div>
        </div>
    </form>

    <div class="settings-form-actions panel-form-actions">
        <x-ui.button type="submit" variant="primary" form="technical-account-form">
            {{ $editing ? 'Сохранить' : 'Сохранить и подключить' }}
        </x-ui.button>
    </div>
</section>

@if($editing)
    @php
        $connected = $account->status === 'connected';
    @endphp
    <section class="panel authorization-card">
        <div class="panel-header">
            <div>
                <div class="panel-title">Авторизация Telegram</div>
                <div class="panel-copy">Код и пароль 2FA не сохраняются. Сохраняется только зашифрованная сессия.</div>
            </div>
            <span class="status-pill status-pill-{{ $account->statusState() }}">
                {{ $connected ? 'Подключён' : ($account->statusState() === 'waiting' ? 'Ожидание' : 'Не подключён') }}
            </span>
        </div>

        <div class="panel-body authorization-body">
            @if($connected)
                <div class="connected-account">
                    <div class="technical-account-icon"><x-icon name="account" :size="20"/></div>
                    <div>
                        <strong>{{ $account->telegram_name ?: $account->name }}</strong>
                        <span>{{ $account->telegram_username ? '@'.$account->telegram_username : 'без username' }}</span>
                    </div>
                </div>

                <form method="POST" action="{{ route('news.accounts.disconnect', [$telegramApp, $account]) }}" onsubmit="return confirm('Отключить техаккаунт и удалить зашифрованную сессию?')">
                    @csrf
                    <x-ui.button type="submit" variant="ghost">Отключить Telegram</x-ui.button>
                </form>
            @elseif($account->login_method === 'phone')
                <form class="inline-auth-form" method="POST" action="{{ route('news.accounts.phone.start', [$telegramApp, $account]) }}">
                    @csrf
                    <div>
                        <strong>Шаг 1. Получить код</strong>
                        <span>Telegram отправит код на номер {{ $account->phone }}.</span>
                    </div>
                    <x-ui.button type="submit" variant="primary">Получить код</x-ui.button>
                </form>

                @if($account->status === 'waiting_code')
                    <form class="inline-auth-form" method="POST" action="{{ route('news.accounts.phone.complete', [$telegramApp, $account]) }}">
                        @csrf
                        <label class="field">
                            <span class="field-label">Шаг 2. Код из Telegram</span>
                            <input class="input" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required>
                        </label>
                        <x-ui.button type="submit" variant="primary">Подтвердить код</x-ui.button>
                    </form>
                @endif
            @else
                <div
                    class="qr-login"
                    x-data="{
                        loading: false,
                        qr: '',
                        error: '',
                        stopped: false,
                        async refresh() {
                            if (this.stopped || this.loading) return;
                            this.loading = true;
                            try {
                                const response = await fetch(@js(route('news.accounts.qr', [$telegramApp, $account])), {
                                    method: 'POST',
                                    headers: {
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': @js(csrf_token())
                                    }
                                });
                                const data = await response.json();
                                if (!response.ok) throw new Error(data.message || 'Не удалось получить QR-код');
                                if (data.connected || data.needs_password) {
                                    this.stopped = true;
                                    window.location.reload();
                                    return;
                                }
                                this.qr = data.svg || '';
                                this.error = '';
                                window.setTimeout(() => this.refresh(), Math.max(3000, Math.min(15000, ((data.expires_in || 10) - 1) * 1000)));
                            } catch (error) {
                                this.error = error.message;
                                window.setTimeout(() => this.refresh(), 5000);
                            } finally {
                                this.loading = false;
                            }
                        }
                    }"
                    x-init="refresh()"
                >
                    <div class="qr-live" x-html="qr" x-show="qr"></div>
                    <div class="loading-state" x-show="loading && !qr"><span class="spinner"></span> Получаем QR-код…</div>
                    <p>Telegram → Настройки → Устройства → Подключить устройство.</p>
                    <small class="resource-error" x-text="error" x-show="error"></small>
                </div>
            @endif

            @if($account->status === 'waiting_password')
                <form class="inline-auth-form" method="POST" action="{{ route('news.accounts.password', [$telegramApp, $account]) }}">
                    @csrf
                    <label class="field">
                        <span class="field-label">Пароль двухэтапной защиты Telegram</span>
                        <input class="input" name="password" type="password" autocomplete="current-password" required>
                    </label>
                    <x-ui.button type="submit" variant="primary">Подтвердить пароль</x-ui.button>
                </form>
            @endif
        </div>
    </section>

    <form class="danger-form standalone-danger-form" method="POST" action="{{ route('news.accounts.destroy', [$telegramApp, $account]) }}" onsubmit="return confirm('Удалить техаккаунт? Привязанные источники останутся, но будут отключены.')">
        @csrf
        @method('DELETE')
        <x-ui.button type="submit" variant="danger">Удалить техаккаунт</x-ui.button>
    </form>
@endif
@endsection
