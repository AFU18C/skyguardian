<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Админка' }} — SkyGuardian</title>
    @vite([
        'resources/css/app.css',
        'resources/css/source-copying.css',
        'resources/css/collapsible-cards.css',
        'resources/css/topbar-metrics.css',
        'resources/css/group-channel.css',
        'resources/js/app.js',
        'resources/js/group-channel-management.js',
        'resources/js/collapsible-cards.js',
        'resources/js/topbar-metrics.js',
    ])
</head>
<body class="sg-body">
<div class="sg-app" data-admin-app>
    <div class="sg-mobile-overlay" data-sidebar-overlay></div>
    <x-admin.sidebar />
    <div class="sg-shell">
        <header class="sg-topbar">
            <button class="sg-icon-button sg-mobile-menu-button" type="button" data-sidebar-open aria-label="Открыть меню"><span></span><span></span><span></span></button>
            <div class="sg-system-state"><span class="sg-state-dot"></span><span>Система работает</span></div>

            <div class="sg-vps-metrics" data-vps-metrics data-metrics-url="{{ route('admin.system.metrics') }}">
                <span class="sg-vps-metric" data-vps-metric="cpu" title="Загрузка процессора" aria-label="Загрузка процессора">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="6" y="6" width="12" height="12" rx="2"></rect>
                        <rect x="9" y="9" width="6" height="6" rx="1"></rect>
                        <path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"></path>
                    </svg>
                    <strong data-vps-metric-value>--%</strong>
                </span>
                <span class="sg-vps-metric" data-vps-metric="memory" title="Использование оперативной памяти" aria-label="Использование оперативной памяти">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="3" y="7" width="18" height="10" rx="2"></rect>
                        <path d="M7 10v4M11 10v4M15 10v4M19 10v4M6 17v3M18 17v3"></path>
                    </svg>
                    <strong data-vps-metric-value>--%</strong>
                </span>
                <span class="sg-vps-metric" data-vps-metric="disk" title="Использование диска" aria-label="Использование диска">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <ellipse cx="12" cy="5" rx="8" ry="3"></ellipse>
                        <path d="M4 5v7c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7"></path>
                    </svg>
                    <strong data-vps-metric-value>--%</strong>
                </span>
            </div>

            <div class="sg-topbar-right">
                <div class="sg-admin-identity">
                    <div class="sg-avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</div>
                    <div><strong>{{ auth()->user()->name }}</strong><span>Администратор</span></div>
                </div>
                <form method="POST" action="{{ route('admin.logout') }}">@csrf<button class="sg-link-button" type="submit">Выйти</button></form>
            </div>
        </header>
        <main class="sg-main">
            <div class="sg-page-header">
                <div>
                    <h1>{{ $title ?? 'Админка' }}</h1>
                </div>
                @isset($actions)<div class="sg-page-actions">{{ $actions }}</div>@endisset
            </div>
            {{ $slot }}
        </main>
    </div>
</div>
<x-toast />
@stack('modals')
@stack('scripts')
</body>
</html>
