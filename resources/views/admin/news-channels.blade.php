@extends('layouts.admin')

@section('title', 'Каналы данных')
@section('section', 'Новости')
@section('page-title', 'Каналы данных')

@section('content')
<div>
    <div class="content-heading content-heading-actions-only">
        @if($hasConnectedAccounts)
            <x-ui.button variant="primary" :href="route('news.channels.create')">
                <x-icon name="plus" :size="15"/>
                Добавить
            </x-ui.button>
        @else
            <x-ui.button variant="primary" disabled title="Сначала подключите технический аккаунт">
                <x-icon name="plus" :size="15"/>
                Добавить
            </x-ui.button>
        @endif
    </div>

    @if(session('status'))
        <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
    @endif

    @if($errors->any())
        <x-ui.alert>{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="resource-list">
        @forelse($channels as $channel)
            @php
                $state = $channel->statusState();
                $label = match($state) {
                    'working' => 'Работает',
                    'waiting' => 'Ожидание',
                    'error' => 'Ошибка',
                    default => 'Отключён',
                };
            @endphp
            <article class="resource-card">
                <div class="resource-card-icon">
                    <x-icon name="channels" :size="23"/>
                </div>

                <div class="resource-card-content">
                    <div class="resource-card-heading">
                        <h2>{{ $channel->name }}</h2>
                        <span class="status-pill status-pill-{{ $state }}">{{ $label }}</span>
                    </div>
                    <div class="resource-card-details">
                        <span><strong>Источник:</strong> {{ $channel->identifier }}</span>
                        <span><strong>Технический аккаунт:</strong> {{ $channel->telegramAccount?->name ?? 'Техаккаунт удалён' }}</span>
                        <span><strong>Публикация:</strong> {{ $channel->publication_identifier ?: 'Не указана' }}</span>
                        <span><strong>Формат:</strong> {{ $channel->publication_format === 'text' ? 'Только текст' : 'Оригинал' }}</span>
                        <span><strong>Интервал:</strong> каждые {{ $channel->intervalLabel() }}</span>
                        <span><strong>Последняя успешная:</strong> {{ $channel->last_success_at?->format('d.m.Y H:i:s') ?? 'ещё не было' }}</span>
                        <span><strong>Следующая:</strong> {{ $channel->next_check_at?->format('d.m.Y H:i:s') ?? 'не запланирована' }}</span>
                    </div>
                    @if($channel->flood_wait_until?->isFuture())
                        <div class="resource-card-note">Ограничение Telegram до {{ $channel->flood_wait_until->format('d.m.Y H:i:s') }}.</div>
                    @elseif($channel->last_error)
                        <div class="resource-card-note resource-error">{{ $channel->last_error }}</div>
                    @endif
                </div>

                <div class="resource-card-actions">
                    @if($channel->telegramAccount)
                        <form method="POST" action="{{ route('news.channels.check-access', $channel) }}">
                            @csrf
                            <button class="icon-button" type="submit" title="Проверить доступ" aria-label="Проверить доступ">
                                <x-icon name="shield" :size="17"/>
                            </button>
                        </form>
                        @if($channel->is_active)
                            <form method="POST" action="{{ route('news.channels.check-now', $channel) }}">
                                @csrf
                                <button class="icon-button" type="submit" title="Проверить сейчас" aria-label="Проверить сейчас">
                                    <x-icon name="clock" :size="17"/>
                                </button>
                            </form>
                        @endif
                    @endif
                    <form method="POST" action="{{ route('news.channels.toggle', $channel) }}">
                        @csrf
                        @method('PATCH')
                        <label class="resource-switch" aria-label="{{ $channel->is_active ? 'Выключить канал' : 'Включить канал' }}">
                            <input
                                type="checkbox"
                                @checked($channel->is_active)
                                @disabled(! $channel->telegramAccount)
                                onchange="this.form.submit()"
                            >
                            <span></span>
                        </label>
                    </form>
                    <a
                        class="icon-button"
                        href="{{ route('news.channels.edit', $channel) }}"
                        aria-label="Редактировать канал"
                        title="Редактировать"
                    >
                        <x-icon name="edit" :size="18"/>
                    </a>
                </div>
            </article>
        @empty
            <section class="panel empty-state">
                <div>
                    <div class="empty-icon"><x-icon name="channels" :size="24"/></div>
                    <div class="empty-title">Каналы данных ещё не добавлены</div>
                    <p class="empty-copy">Нажмите «Добавить», чтобы перейти к форме.</p>
                </div>
            </section>
        @endforelse
    </div>
</div>
@endsection
