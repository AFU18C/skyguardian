@extends('layouts.public')

@section('title', config('branding.name').' — информационная система')
@section('description', 'SkyGuardian — единая система управления новостями и воздушными тревогами.')

@section('body')
<div class="shell">
    <header class="public-header page-container">
        <x-brand/>
        <x-ui.button :href="route('login')">
            Войти в систему
            <x-icon name="chevron" :size="14"/>
        </x-ui.button>
    </header>

    <main class="public-main">
        <div class="page-container public-grid">
            <section>
                <div class="eyebrow"><span class="eyebrow-dot"></span>Система готовится к запуску</div>
                <h1 class="hero-title">Важная информация. <span>Под надёжным контролем.</span></h1>
                <p class="hero-copy">
                    {{ config('branding.name') }} объединит управление новостными каналами и источниками воздушных тревог в одной защищённой панели.
                </p>
                <div class="hero-actions">
                    <x-ui.button :href="route('login')" variant="primary">
                        Перейти в админ-панель
                        <x-icon name="chevron" :size="15"/>
                    </x-ui.button>
                </div>
            </section>

            <aside class="signal-card glass-panel" aria-label="Разделы системы">
                <div class="signal-top">
                    <div>
                        <div class="signal-title">SkyGuardian Control</div>
                        <div class="signal-subtitle">{{ config('branding.domain') }}</div>
                    </div>
                    <span class="status-pill">Подготовка</span>
                </div>
                <div class="signal-list">
                    <div class="signal-item">
                        <span class="signal-icon"><x-icon name="news" :size="19"/></span>
                        <div><div class="signal-label">Новости</div><div class="signal-note">Независимые каналы и настройки</div></div>
                    </div>
                    <div class="signal-item">
                        <span class="signal-icon"><x-icon name="alert" :size="19"/></span>
                        <div><div class="signal-label">Воздушная тревога</div><div class="signal-note">Отдельный контур источников</div></div>
                    </div>
                    <div class="signal-item">
                        <span class="signal-icon"><x-icon name="shield" :size="19"/></span>
                        <div><div class="signal-label">Закрытая панель</div><div class="signal-note">Доступ только после авторизации</div></div>
                    </div>
                </div>
            </aside>
        </div>
    </main>
</div>
@endsection
