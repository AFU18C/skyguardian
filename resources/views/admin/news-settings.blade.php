@extends('layouts.admin')

@section('title', 'Настройка')
@section('section', 'Новости')
@section('page-title', 'Настройка')

@section('content')
<div>
    <div class="content-heading content-heading-actions-only">
        <x-ui.button variant="primary" :href="route('news.settings.create')">
            <x-icon name="plus" :size="15"/>
            Добавить
        </x-ui.button>
    </div>

    @if(session('status'))
        <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
    @endif

    @if($errors->any())
        <x-ui.alert>{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="resource-list">
        @forelse($apps as $telegramApp)
            @php
                $state = $telegramApp->statusState();
                $label = match($state) {
                    'working' => 'Работает',
                    'waiting' => 'Ожидание',
                    'error' => 'Ошибка',
                    default => 'Отключён',
                };
            @endphp
            <article class="resource-card resource-card-stacked">
                <div class="resource-card-main">
                    <div class="resource-card-icon">
                        <x-icon name="key" :size="23"/>
                    </div>

                    <div class="resource-card-content">
                        <div class="resource-card-heading">
                            <h2>{{ $telegramApp->name }}</h2>
                            <span class="status-pill status-pill-{{ $state }}">{{ $label }}</span>
                        </div>
                        <div class="resource-card-details">
                            <span><strong>Telegram App</strong></span>
                            <span><strong>API ID:</strong> {{ $telegramApp->api_id ?: 'нужно ввести заново' }}</span>
                            <span><strong>Техаккаунтов:</strong> {{ $telegramApp->accounts->count() }}</span>
                        </div>
                    </div>

                    <div class="resource-card-actions">
                        <form method="POST" action="{{ route('news.settings.toggle', $telegramApp) }}">
                            @csrf
                            @method('PATCH')
                            <label class="resource-switch" aria-label="{{ $telegramApp->is_active ? 'Выключить Telegram App' : 'Включить Telegram App' }}">
                                <input type="checkbox" @checked($telegramApp->is_active) onchange="this.form.submit()">
                                <span></span>
                            </label>
                        </form>
                        <a
                            class="icon-button"
                            href="{{ route('news.settings.edit', $telegramApp) }}"
                            aria-label="Редактировать Telegram App"
                            title="Редактировать"
                        >
                            <x-icon name="edit" :size="18"/>
                        </a>
                    </div>
                </div>

                <div class="technical-accounts">
                    <div class="technical-accounts-heading">
                        <div>
                            <strong>Технические аккаунты</strong>
                            <span>Одна авторизация — постоянная зашифрованная сессия.</span>
                        </div>
                        <x-ui.button variant="ghost" :href="route('news.accounts.create', $telegramApp)">
                            <x-icon name="plus" :size="14"/>
                            Добавить техаккаунт
                        </x-ui.button>
                    </div>

                    @forelse($telegramApp->accounts as $account)
                        @php
                            $accountState = $account->statusState();
                            $accountLabel = match($accountState) {
                                'working' => 'Работает',
                                'waiting' => 'Ожидание',
                                'error' => 'Ошибка',
                                default => 'Отключён',
                            };
                        @endphp
                        <div class="technical-account-row">
                            <div class="technical-account-icon"><x-icon name="account" :size="18"/></div>
                            <div class="technical-account-copy">
                                <div>
                                    <strong>{{ $account->name }}</strong>
                                    <span class="status-pill status-pill-{{ $accountState }}">{{ $accountLabel }}</span>
                                </div>
                                <small>
                                    {{ $account->telegram_name ?: 'Telegram ещё не подключён' }}
                                    @if($account->telegram_username)
                                        · {{ '@'.$account->telegram_username }}
                                    @endif
                                    @if($account->flood_wait_until?->isFuture())
                                        · ограничение до {{ $account->flood_wait_until->format('H:i:s') }}
                                    @endif
                                </small>
                                @if($account->last_error)
                                    <small class="resource-error">{{ $account->last_error }}</small>
                                @endif
                            </div>
                            <div class="technical-account-actions">
                                <form method="POST" action="{{ route('news.accounts.toggle', [$telegramApp, $account]) }}">
                                    @csrf
                                    @method('PATCH')
                                    <label class="resource-switch" aria-label="{{ $account->is_active ? 'Выключить техаккаунт' : 'Включить техаккаунт' }}">
                                        <input type="checkbox" @checked($account->is_active) onchange="this.form.submit()">
                                        <span></span>
                                    </label>
                                </form>
                                <a
                                    class="icon-button"
                                    href="{{ route('news.accounts.edit', [$telegramApp, $account]) }}"
                                    aria-label="Редактировать техаккаунт"
                                    title="Редактировать"
                                >
                                    <x-icon name="edit" :size="17"/>
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="technical-account-empty">
                            Техаккаунты ещё не добавлены. Добавьте аккаунт и авторизуйте его по телефону или QR-коду.
                        </div>
                    @endforelse
                </div>
            </article>
        @empty
            <section class="panel empty-state">
                <div>
                    <div class="empty-icon"><x-icon name="settings" :size="24"/></div>
                    <div class="empty-title">Данные ещё не добавлены</div>
                    <p class="empty-copy">Нажмите «Добавить», укажите данные Telegram App, затем подключите технический аккаунт.</p>
                </div>
            </section>
        @endforelse
    </div>
</div>
@endsection
