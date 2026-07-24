@extends('layouts.admin')

@section('title', 'Главная')
@section('section', 'Панель управления')
@section('page-title', 'Главная')

@section('content')
<div class="content-heading">
    <div>
        <h1>Главная</h1>
        <p>Общий шаблон административной панели {{ config('branding.name') }}. Функциональные показатели появятся после реализации соответствующих разделов.</p>
    </div>
</div>

<section class="metrics-grid" aria-label="Обзор разделов">
    <article class="metric-card">
        <div class="metric-head">
            <div>
                <div class="metric-label">Источники новостей</div>
                <div class="metric-value">0</div>
            </div>
            <span class="metric-icon"><x-icon name="news"/></span>
        </div>
        <div class="metric-state">Не настроены</div>
    </article>
    <article class="metric-card">
        <div class="metric-head">
            <div>
                <div class="metric-label">Источники тревог</div>
                <div class="metric-value">0</div>
            </div>
            <span class="metric-icon"><x-icon name="alert"/></span>
        </div>
        <div class="metric-state">Не настроены</div>
    </article>
    <article class="metric-card">
        <div class="metric-head">
            <div>
                <div class="metric-label">Технические аккаунты</div>
                <div class="metric-value">0</div>
            </div>
            <span class="metric-icon"><x-icon name="account"/></span>
        </div>
        <div class="metric-state">Не добавлены</div>
    </article>
</section>

<section class="panel">
    <header class="panel-header">
        <div>
            <div class="panel-title">Последние события</div>
            <div class="panel-copy">Журнал будет заполнен после подключения разделов.</div>
        </div>
    </header>
    <x-ui.empty-state
        title="Событий пока нет"
        description="Здесь будут отображаться системные события после запуска функциональных модулей."
        icon="clock"
    />
</section>
@endsection
