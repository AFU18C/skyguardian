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
        'resources/css/source-polling-settings.css',
        'resources/css/collapsible-cards.css',
        'resources/css/topbar-metrics.css',
        'resources/css/source-health.css',
        'resources/css/group-channel.css',
        'resources/css/site-settings.css',
        'resources/js/app.js',
        'resources/js/group-channel-management.js',
        'resources/js/collapsible-cards.js',
        'resources/js/topbar-metrics.js',
        'resources/js/site-page-editor.js',
        'resources/js/site-settings.js',
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
                <div class="sg-page-title-row">
                    <h1>{{ $title ?? 'Админка' }}</h1>
                    @isset($titleActions)<div class="sg-page-title-actions">{{ $titleActions }}</div>@endisset
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
