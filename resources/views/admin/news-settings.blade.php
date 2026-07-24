@extends('layouts.admin')

@section('title', 'Настройка')
@section('section', 'Новости')
@section('page-title', 'Настройка')

@section('content')
<div>
    <div class="content-heading">
        <div>
            <h1>Настройка</h1>
            <p>Telegram API и технические аккаунты новостей.</p>
        </div>
        <x-ui.button variant="primary" :href="route('news.settings.create')">
            <x-icon name="plus" :size="15"/>
            Добавить
        </x-ui.button>
    </div>

    @if(session('status'))
        <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
    @endif

    <div class="resource-list">
        @forelse($accounts as $account)
            @php
                $state = $account->statusState();
                $label = match($state) {
                    'working' => 'Работает',
                    'error' => 'Ошибка',
                    default => 'Выключен',
                };
            @endphp
            <article class="resource-card">
                <div class="resource-card-icon">
                    <x-icon name="account" :size="23"/>
                </div>

                <div class="resource-card-content">
                    <div class="resource-card-heading">
                        <h2>{{ $account->name }}</h2>
                        <span class="status-pill status-pill-{{ $state }}">{{ $label }}</span>
                    </div>
                    <div class="resource-card-meta">
                        API ID: {{ filled($account->api_id) ? $account->api_id : 'нужно ввести заново' }}
                    </div>
                    <div class="resource-card-note">
                        {{ $state === 'working' ? 'Технический аккаунт подключён' : ($account->last_error ?: 'Технический аккаунт выключен') }}
                    </div>
                </div>

                <div class="resource-card-actions">
                    <form method="POST" action="{{ route('news.settings.toggle', $account) }}">
                        @csrf
                        @method('PATCH')
                        <label class="resource-switch" aria-label="{{ $state === 'off' ? 'Включить настройку' : 'Выключить настройку' }}">
                            <input
                                type="checkbox"
                                @checked($state !== 'off')
                                onchange="this.form.submit()"
                            >
                            <span></span>
                        </label>
                    </form>
                    <a
                        class="icon-button"
                        href="{{ route('news.settings.edit', $account) }}"
                        aria-label="Редактировать настройку"
                        title="Редактировать"
                    >
                        <x-icon name="edit" :size="18"/>
                    </a>
                </div>
            </article>
        @empty
            <section class="panel empty-state">
                <div>
                    <div class="empty-icon"><x-icon name="settings" :size="24"/></div>
                    <div class="empty-title">Настройки ещё не добавлены</div>
                    <p class="empty-copy">Нажмите «Добавить», чтобы перейти к форме.</p>
                </div>
            </section>
        @endforelse
    </div>
</div>
@endsection
