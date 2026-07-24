@extends('layouts.admin')

@section('title', 'Каналы данных')
@section('section', 'Новости')
@section('page-title', 'Каналы данных')

@section('content')
<div>
    <div class="content-heading">
        <div>
            <h1>Каналы данных</h1>
            <p>Источники сообщений и каналы публикации новостей.</p>
        </div>
        <x-ui.button
            variant="primary"
            :href="route('news.channels.create')"
        >
            <x-icon name="plus" :size="15"/>
            Добавить
        </x-ui.button>
    </div>

    <div class="resource-list">
        <article class="resource-card">
            <div class="resource-card-icon">
                <x-icon name="channels" :size="23"/>
            </div>

            <div class="resource-card-content">
                <div class="resource-card-heading">
                    <h2>Новости города</h2>
                    <span class="status-pill">Включён</span>
                </div>
                <div class="resource-card-details">
                    <span><strong>Источник:</strong> @source_channel</span>
                    <span><strong>Технический аккаунт:</strong> Telegram 33042494</span>
                    <span><strong>Публикация:</strong> @destination_channel</span>
                    <span><strong>Формат:</strong> Оригинал</span>
                </div>
            </div>

            <div class="resource-card-actions">
                <label class="resource-switch" aria-label="Отключить канал">
                    <input type="checkbox" checked>
                    <span></span>
                </label>
                <a
                    class="icon-button"
                    href="{{ route('news.channels.edit', ['channel' => 1]) }}"
                    aria-label="Редактировать канал"
                    title="Редактировать"
                >
                    <x-icon name="edit" :size="18"/>
                </a>
            </div>
        </article>
    </div>
</div>
@endsection
