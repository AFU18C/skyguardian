<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'SkyGuardian')</title>

    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v=0.5.0">
</head>
<body>
<div class="admin-layout">
    <header class="mobile-header">
        <a href="{{ route('home') }}" class="mobile-brand">SkyGuardian</a>

        <button
            type="button"
            class="menu-toggle"
            id="menu-toggle"
            aria-label="Открыть меню"
            aria-expanded="false"
        >
            <span></span>
            <span></span>
            <span></span>
        </button>
    </header>

    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="{{ route('home') }}" class="brand">SkyGuardian</a>

            <button
                type="button"
                class="sidebar-close"
                id="sidebar-close"
                aria-label="Закрыть меню"
            >
                ×
            </button>
        </div>

        <nav class="navigation">
            <a
                href="{{ route('home') }}"
                class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}"
            >
                <span class="nav-icon">🏠</span>
                <span>Главная</span>
            </a>

            <div class="nav-group">
                <a
                    href="{{ route('news.index') }}"
                    class="nav-heading nav-heading-link {{ request()->routeIs('news.index') ? 'active' : '' }}"
                >
                    <span class="nav-icon">📰</span>
                    <span>Новости</span>
                </a>

                <a
                    href="{{ route('news.sources') }}"
                    class="nav-link nav-child {{ request()->routeIs('news.sources*') ? 'active' : '' }}"
                >
                    Источники
                </a>

                <a
                    href="{{ route('news.settings') }}"
                    class="nav-link nav-child {{ request()->routeIs('news.settings') ? 'active' : '' }}"
                >
                    Настройки
                </a>
            </div>

            <div class="nav-group">
                <a
                    href="{{ route('alerts.index') }}"
                    class="nav-heading nav-heading-link {{ request()->routeIs('alerts.index') ? 'active' : '' }}"
                >
                    <span class="nav-icon">🚨</span>
                    <span>Воздушная тревога</span>
                </a>

                <a
                    href="{{ route('alerts.sources') }}"
                    class="nav-link nav-child {{ request()->routeIs('alerts.sources*') ? 'active' : '' }}"
                >
                    Источники
                </a>

                <a
                    href="{{ route('alerts.settings') }}"
                    class="nav-link nav-child {{ request()->routeIs('alerts.settings') ? 'active' : '' }}"
                >
                    Настройки
                </a>
            </div>
        </nav>
    </aside>

    <main class="main-content">
        <header class="page-header">
            @hasSection('section')
                <div class="page-section">@yield('section')</div>
            @endif

            <h1>@yield('heading', 'Главная')</h1>
        </header>

        <div class="page-content">
            @yield('content')
        </div>
    </main>
</div>

<script>
    const menuToggle = document.getElementById('menu-toggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarClose = document.getElementById('sidebar-close');
    const sidebarOverlay = document.getElementById('sidebar-overlay');

    function openMenu() {
        sidebar.classList.add('open');
        sidebarOverlay.classList.add('visible');
        document.body.classList.add('menu-open');
        menuToggle.setAttribute('aria-expanded', 'true');
    }

    function closeMenu() {
        sidebar.classList.remove('open');
        sidebarOverlay.classList.remove('visible');
        document.body.classList.remove('menu-open');
        menuToggle.setAttribute('aria-expanded', 'false');
    }

    menuToggle.addEventListener('click', openMenu);
    sidebarClose.addEventListener('click', closeMenu);
    sidebarOverlay.addEventListener('click', closeMenu);

    document.querySelectorAll('.sidebar a').forEach((link) => {
        link.addEventListener('click', closeMenu);
    });
</script>
    @stack('scripts')
</body>
</html>
