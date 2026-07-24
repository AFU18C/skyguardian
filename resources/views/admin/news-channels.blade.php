@extends('layouts.admin')

@section('title', 'Каналы данных')
@section('section', 'Новости')
@section('page-title', 'Каналы данных')

@section('content')
<div>
    <div class="content-heading content-heading-actions-only">
        <x-ui.button variant="primary" :href="route('news.channels.create')">
            <x-icon name="plus" :size="15"/>
            Добавить
        </x-ui.button>
    </div>

    @if(session('status'))
        <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
    @endif

    <div class="resource-list">
        @forelse($channels as $channel)
            @php
                $state = $channel->statusState();
                $label = match($state) {
                    'working' => 'Работает',
                    'error' => 'Ошибка',
                    default => 'Выключен',
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
                        <span><strong>Технический аккаунт:</strong> {{ $channel->telegramAccount?->name ?? 'Не выбран' }}</span>
                        <span><strong>Публикация:</strong> {{ $channel->publication_identifier ?: 'Не указана' }}</span>
                        <span><strong>Формат:</strong> {{ $channel->publication_format === 'text' ? 'Только текст' : 'Оригинал' }}</span>
                    </div>
                </div>

                <div class="resource-card-actions">
                    <form method="POST" action="{{ route('news.channels.toggle', $channel) }}">
                        @csrf
                        @method('PATCH')
                        <label class="resource-switch" aria-label="{{ $channel->is_active ? 'Выключить канал' : 'Включить канал' }}">
                            <input
                                type="checkbox"
                                @checked($channel->is_active)
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
