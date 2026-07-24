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
        <x-ui.button
            variant="primary"
            :href="route('news.settings.create')"
        >
            <x-icon name="plus" :size="15"/>
            Добавить
        </x-ui.button>
    </div>

    <div class="resource-list">
        <article class="resource-card">
            <div class="resource-card-icon">
                <x-icon name="account" :size="23"/>
            </div>

            <div class="resource-card-content">
                <div class="resource-card-heading">
                    <h2>Telegram 33042494</h2>
                    <span class="status-pill">Включён</span>
                </div>
                <div class="resource-card-meta">API ID: 33042494</div>
                <div class="resource-card-note">Технический аккаунт подключён</div>
            </div>

            <div class="resource-card-actions">
                <label class="resource-switch" aria-label="Отключить настройку">
                    <input type="checkbox" checked>
                    <span></span>
                </label>
                <a
                    class="icon-button"
                    href="{{ route('news.settings.edit', ['account' => 1]) }}"
                    aria-label="Редактировать настройку"
                    title="Редактировать"
                >
                    <x-icon name="edit" :size="18"/>
                </a>
            </div>
        </article>
    </div>
</div>
@endsection
