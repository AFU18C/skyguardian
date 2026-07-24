<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070d18">
    <title>@yield('title', 'Главная') — {{ config('branding.name') }}</title>
    <style>
        :root {
            --brand-primary: {{ config('branding.primary_color') }};
            --brand-accent: {{ config('branding.accent_color') }};
        }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@php
    $newsActive = request()->routeIs('news.*');
    $alertsActive = request()->routeIs('alerts.*');
    $settingsActive = request()->routeIs('settings.*');
@endphp
<div
    class="admin-shell"
    x-data="{
        mobileOpen: false,
        collapsed: localStorage.getItem('sg-sidebar-collapsed') === 'true',
        newsOpen: {{ $newsActive ? 'true' : 'false' }},
        alertsOpen: {{ $alertsActive ? 'true' : 'false' }},
        settingsOpen: {{ $settingsActive ? 'true' : 'false' }},
        toggleCollapsed() {
            this.collapsed = !this.collapsed;
            localStorage.setItem('sg-sidebar-collapsed', this.collapsed);
        }
    }"
    :class="{ 'admin-collapsed': collapsed }"
>
    <div x-cloak x-show="mobileOpen" x-transition.opacity class="sidebar-overlay" @click="mobileOpen = false"></div>

    <aside class="sidebar" :class="{ 'mobile-open': mobileOpen }">
        <div class="sidebar-header">
            <x-brand :href="route('dashboard')"/>
            <button class="icon-button desktop-collapse-button" type="button" @click="toggleCollapsed()" aria-label="Свернуть меню">
                <x-icon name="collapse" :size="17" x-bind:style="collapsed ? 'transform: rotate(180deg)' : ''"/>
            </button>
        </div>

        <nav class="sidebar-nav" aria-label="Основное меню">
            <div class="nav-group">
                <span class="nav-label">Панель</span>
                <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <span class="nav-icon"><x-icon name="home"/></span>
                    <span class="nav-text">Главная</span>
                </a>
            </div>

            <div class="nav-group">
                <button type="button" class="nav-parent {{ $newsActive ? 'active' : '' }}" @click="newsOpen = !newsOpen; if (collapsed) toggleCollapsed()">
                    <span class="nav-icon"><x-icon name="news"/></span>
                    <span class="nav-text">Новости</span>
                    <x-icon name="chevron" :size="14" class="nav-chevron" x-bind:class="{ 'open': newsOpen }"/>
                </button>
                <div x-cloak x-show="newsOpen && !collapsed" x-transition class="nav-children">
                    <a href="{{ route('news.channels') }}" class="nav-child {{ request()->routeIs('news.channels') ? 'active' : '' }}">Каналы данных</a>
                    <a href="{{ route('news.settings') }}" class="nav-child {{ request()->routeIs('news.settings') ? 'active' : '' }}">Настройка</a>
                </div>
            </div>

            <div class="nav-group">
                <button type="button" class="nav-parent {{ $alertsActive ? 'active' : '' }}" @click="alertsOpen = !alertsOpen; if (collapsed) toggleCollapsed()">
                    <span class="nav-icon"><x-icon name="alert"/></span>
                    <span class="nav-text">Воздушная тревога</span>
                    <x-icon name="chevron" :size="14" class="nav-chevron" x-bind:class="{ 'open': alertsOpen }"/>
                </button>
                <div x-cloak x-show="alertsOpen && !collapsed" x-transition class="nav-children">
                    <a href="{{ route('alerts.channels') }}" class="nav-child {{ request()->routeIs('alerts.channels') ? 'active' : '' }}">Каналы данных</a>
                    <a href="{{ route('alerts.settings') }}" class="nav-child {{ request()->routeIs('alerts.settings') ? 'active' : '' }}">Настройка</a>
                </div>
            </div>

            <div class="nav-group">
                <span class="nav-label">Система</span>
                <button type="button" class="nav-parent {{ $settingsActive ? 'active' : '' }}" @click="settingsOpen = !settingsOpen; if (collapsed) toggleCollapsed()">
                    <span class="nav-icon"><x-icon name="settings"/></span>
                    <span class="nav-text">Общие настройки</span>
                    <x-icon name="chevron" :size="14" class="nav-chevron" x-bind:class="{ 'open': settingsOpen }"/>
                </button>
                <div x-cloak x-show="settingsOpen && !collapsed" x-transition class="nav-children">
                    <a href="{{ route('settings.groups') }}" class="nav-child {{ request()->routeIs('settings.groups') ? 'active' : '' }}">Управление группой</a>
                    <a href="{{ route('settings.site') }}" class="nav-child {{ request()->routeIs('settings.site') ? 'active' : '' }}">Управление сайтом</a>
                </div>
            </div>
        </nav>

        <div class="sidebar-footer">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="nav-link">
                    <span class="nav-icon"><x-icon name="logout"/></span>
                    <span class="nav-text">Выйти</span>
                </button>
            </form>
        </div>
    </aside>

    <main class="admin-main">
        <header class="topbar">
            <div class="topbar-side">
                <button class="icon-button mobile-menu-button" type="button" @click="mobileOpen = true" aria-label="Открыть меню">
                    <x-icon name="menu" :size="19"/>
                </button>
                <div>
                    <div class="breadcrumb">@yield('section', 'Панель управления')</div>
                    <div class="topbar-title">@yield('page-title', 'Главная')</div>
                </div>
            </div>

            <div class="user-menu" x-data="{ open: false }">
                <button class="user-trigger" type="button" @click="open = !open" aria-label="Меню пользователя">
                    <span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="user-name">{{ auth()->user()->name }}</span>
                    <x-icon name="chevron" :size="13"/>
                </button>
                <div x-cloak x-show="open" x-transition.origin.top.right @click.outside="open = false" class="dropdown">
                    <div class="dropdown-meta">{{ auth()->user()->email }}<br>{{ config('branding.domain') }}</div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="dropdown-action" type="submit"><x-icon name="logout" :size="15"/>Выйти</button>
                    </form>
                </div>
            </div>
        </header>

        <div class="admin-content">
            @yield('content')
        </div>
    </main>
</div>
@stack('modals')
</body>
</html>
