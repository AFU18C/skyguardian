<aside class="sg-sidebar" data-sidebar>
    <div class="sg-brand">
        <div class="sg-brand-mark">SG</div>
        <div>
            <strong>SKYGUARDIAN</strong>
            <span>Система мониторинга</span>
        </div>
        <button class="sg-sidebar-close" type="button" data-sidebar-close aria-label="Закрыть меню">×</button>
    </div>

    <nav class="sg-nav" aria-label="Главное меню">
        <a href="{{ route('admin.dashboard') }}" @class(['is-active' => request()->routeIs('admin.dashboard')])>
            <span class="sg-nav-icon">⌂</span>
            <span>Главная</span>
        </a>
        <a href="{{ route('admin.news.index') }}" @class(['is-active' => request()->routeIs('admin.news.*')])>
            <span class="sg-nav-icon">▤</span>
            <span>Новости</span>
        </a>
        <a href="{{ route('admin.air-alert.index') }}" @class(['is-active' => request()->routeIs('admin.air-alert.*')])>
            <span class="sg-nav-icon">▲</span>
            <span>Воздушная тревога</span>
        </a>
        <a href="{{ route('admin.telegram.index') }}" @class(['is-active' => request()->routeIs('admin.telegram.*')])>
            <span class="sg-nav-icon">➤</span>
            <span>Настройки Telegram</span>
        </a>
    </nav>

    <div class="sg-sidebar-status">
        <span>Часовой пояс</span>
        <strong>Europe/Kyiv</strong>
        <small>Все даты отображаются по Киеву</small>
    </div>

    <div class="sg-sidebar-footer">
        <span class="sg-state-dot"></span>
        <span>Защищённое соединение</span>
    </div>
</aside>
