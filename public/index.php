<?php

$page = $_GET['page'] ?? 'home';
$allowedPages = [
    'home', 'news-sources', 'news-settings', 'alerts-sources',
    'alerts-settings', 'group',
];

if (!in_array($page, $allowedPages, true)) {
    $page = 'home';
}

$pageMeta = [
    'home' => ['Главная', 'Обзор системы'],
    'news-sources' => ['Каналы данных', 'Новости'],
    'news-settings' => ['Настройка', 'Новости'],
    'alerts-sources' => ['Каналы данных', 'Воздушная тревога'],
    'alerts-settings' => ['Настройка', 'Воздушная тревога'],
    'group' => ['Управление группой', 'Общие настройки'],
];

[$title, $section] = $pageMeta[$page];
$isSources = str_ends_with($page, '-sources');
$isSettings = str_ends_with($page, '-settings');
$isAlerts = str_starts_with($page, 'alerts-');
$accent = $isAlerts ? 'red' : 'blue';

function active(string $current, string $target): string
{
    return $current === $target ? ' active' : '';
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b1020">
    <title><?= htmlspecialchars($title) ?> — SkyGuardian</title>
    <link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 2.7 20 6v5.6c0 5.1-3.4 8.1-8 9.7-4.6-1.6-8-4.6-8-9.7V6l8-3.3Zm0 4.1-4.2 1.7v3.2c0 2.8 1.6 4.7 4.2 5.9 2.6-1.2 4.2-3.1 4.2-5.9V8.5L12 6.8Z"/></svg>
            </div>
            <div><strong>SkyGuardian</strong><span>CONTROL CENTER</span></div>
        </div>

        <nav class="nav">
            <a class="nav-link<?= active($page, 'home') ?>" href="?page=home">
                <span class="nav-icon">⌂</span><span>Главная</span>
            </a>

            <div class="nav-heading">НОВОСТИ</div>
            <a class="nav-link<?= active($page, 'news-sources') ?>" href="?page=news-sources"><span class="nav-icon">◉</span><span>Каналы данных</span></a>
            <a class="nav-link<?= active($page, 'news-settings') ?>" href="?page=news-settings"><span class="nav-icon">⚙</span><span>Настройка</span></a>

            <div class="nav-heading">ВОЗДУШНАЯ ТРЕВОГА</div>
            <a class="nav-link<?= active($page, 'alerts-sources') ?>" href="?page=alerts-sources"><span class="nav-icon">◉</span><span>Каналы данных</span></a>
            <a class="nav-link<?= active($page, 'alerts-settings') ?>" href="?page=alerts-settings"><span class="nav-icon">⚙</span><span>Настройка</span></a>

            <div class="nav-heading">ОБЩИЕ НАСТРОЙКИ</div>
            <a class="nav-link<?= active($page, 'group') ?>" href="?page=group"><span class="nav-icon">♟</span><span>Управление группой</span></a>
            <button class="nav-link logout" type="button" data-toast="Выход будет подключён на этапе функционала"><span class="nav-icon">↪</span><span>Выйти</span></button>
        </nav>

        <div class="sidebar-footer"><span class="status-dot online"></span><div><strong>Система доступна</strong><small>Макет интерфейса</small></div></div>
    </aside>

    <div class="sidebar-overlay" data-menu-close></div>

    <main class="main">
        <header class="topbar">
            <button class="icon-button menu-button" type="button" data-menu aria-label="Открыть меню">☰</button>
            <div class="breadcrumbs"><span><?= htmlspecialchars($section) ?></span><b>/</b><strong><?= htmlspecialchars($title) ?></strong></div>
            <div class="top-actions">
                <button class="icon-button" type="button" data-toast="Новых уведомлений нет" aria-label="Уведомления">♢<i></i></button>
                <div class="user-chip"><div class="avatar">A</div><div><strong>Администратор</strong><span>Главный аккаунт</span></div></div>
            </div>
        </header>

        <div class="content">
            <?php if ($page === 'home'): ?>
                <section class="hero">
                    <div><span class="eyebrow">ПАНЕЛЬ УПРАВЛЕНИЯ</span><h1>Добро пожаловать в SkyGuardian</h1><p>Управляйте источниками, подключениями и публикациями из единого защищённого пространства.</p></div>
                    <div class="hero-shield"><span>✓</span></div>
                </section>

                <section class="stats-grid">
                    <article class="stat-card"><span class="stat-icon blue">◉</span><div><small>Источники новостей</small><strong>0</strong><span>Не настроено</span></div></article>
                    <article class="stat-card"><span class="stat-icon red">⌁</span><div><small>Источники тревог</small><strong>0</strong><span>Не настроено</span></div></article>
                    <article class="stat-card"><span class="stat-icon violet">♟</span><div><small>Места публикации</small><strong>0</strong><span>Не настроено</span></div></article>
                    <article class="stat-card"><span class="stat-icon green">✓</span><div><small>Состояние системы</small><strong class="word">Готово</strong><span class="success">Шаблон активен</span></div></article>
                </section>

                <section class="dashboard-grid">
                    <article class="panel">
                        <div class="panel-head"><div><span class="eyebrow">БЫСТРЫЙ СТАРТ</span><h2>Подключите первый раздел</h2></div></div>
                        <div class="steps">
                            <a href="?page=news-sources"><span>1</span><div><strong>Добавьте Telegram API</strong><small>API ID и API Hash для подключения</small></div><b>›</b></a>
                            <a href="?page=news-sources"><span>2</span><div><strong>Подключите технический аккаунт</strong><small>Безопасная авторизация по QR-коду</small></div><b>›</b></a>
                            <a href="?page=news-settings"><span>3</span><div><strong>Настройте обработку</strong><small>Источники и правила публикации</small></div><b>›</b></a>
                        </div>
                    </article>
                    <article class="panel activity-panel">
                        <div class="panel-head"><div><span class="eyebrow">АКТИВНОСТЬ</span><h2>Последние события</h2></div><button class="text-button" data-toast="История пока пуста">Все события</button></div>
                        <div class="empty-state"><div>⌁</div><strong>Событий пока нет</strong><p>Здесь появятся изменения статусов и результаты действий.</p></div>
                    </article>
                </section>

            <?php elseif ($isSources): ?>
                <section class="page-title"><div><span class="eyebrow <?= $accent ?>"><?= $isAlerts ? 'ВОЗДУШНАЯ ТРЕВОГА' : 'НОВОСТИ' ?></span><h1>Каналы данных</h1><p>Настройте Telegram API и технический аккаунт для чтения источников.</p></div><div class="section-badge <?= $accent ?>"><?= $isAlerts ? '⌁' : '◉' ?></div></section>

                <div class="notice info"><span>i</span><p><strong>Раздел работает независимо.</strong> Подключения и настройки <?= $isAlerts ? 'воздушных тревог' : 'новостей' ?> не пересекаются с другим разделом.</p></div>

                <section class="workspace-grid">
                    <article class="panel api-panel">
                        <div class="panel-head"><div><span class="step-label">ШАГ 1</span><h2>Telegram API</h2><p>Данные приложения из my.telegram.org</p></div><span class="status-pill off"><i></i>Не настроено</span></div>
                        <form class="form-grid api-form" data-api-form>
                            <label class="full"><span>Название подключения</span><input name="name" placeholder="Например: Основной API"></label>
                            <label><span>API ID</span><input name="api_id" inputmode="numeric" placeholder="12345678"></label>
                            <label><span>API Hash</span><div class="input-action"><input name="api_hash" type="password" placeholder="Введите API Hash"><button type="button" data-password>◉</button></div></label>
                            <div class="form-actions full"><span class="form-hint">Все поля обязательны</span><button class="button primary" type="submit">Сохранить API</button></div>
                        </form>
                    </article>

                    <article class="panel account-panel" data-account>
                        <div class="panel-head account-summary">
                            <div><span class="step-label">ШАГ 2</span><h2>Технический аккаунт</h2><p>Аккаунт для получения сообщений</p></div>
                            <div class="account-controls"><label class="switch" title="Включить или выключить"><input type="checkbox" disabled><span></span></label><button class="edit-button" type="button" data-account-edit aria-label="Открыть аккаунт">✎</button></div>
                        </div>
                        <div class="account-closed"><div class="empty-inline"><span>◇</span><div><strong>Аккаунт не подключён</strong><p>Сохраните API, затем подключитесь по QR-коду.</p></div></div><button class="button secondary" type="button" data-qr disabled>Подключить по QR-коду</button></div>
                        <div class="account-details" data-account-details>
                            <div class="details-grid"><label><span>Имя аккаунта</span><input value="Не подключён" disabled></label><label><span>Telegram ID</span><input value="—" disabled></label><label><span>Номер телефона</span><input value="—" disabled></label><label><span>Дата подключения</span><input value="—" disabled></label></div>
                            <div class="form-actions danger-row"><button class="button danger" type="button" data-confirm-delete>Удалить</button><button class="button primary" type="button" data-save-account>Сохранить</button></div>
                        </div>
                    </article>
                </section>

            <?php elseif ($isSettings): ?>
                <section class="page-title"><div><span class="eyebrow <?= $accent ?>"><?= $isAlerts ? 'ВОЗДУШНАЯ ТРЕВОГА' : 'НОВОСТИ' ?></span><h1>Настройка</h1><p>Будущие правила получения, обработки и публикации сообщений.</p></div><div class="section-badge <?= $accent ?>">⚙</div></section>
                <section class="settings-list">
                    <?php foreach ([['Источник сообщений','Telegram-канал, откуда будут поступать сообщения'],['Обработка сообщений','Правила для текста, ссылок и медиа'],['Защита от повторов','Проверка сообщений перед публикацией'],['Место публикации','Группа или канал для отправки сообщений']] as $index => $item): ?>
                        <article class="panel spoiler" data-spoiler>
                            <button class="spoiler-head" type="button" data-spoiler-button><span class="setting-number">0<?= $index + 1 ?></span><span><strong><?= $item[0] ?></strong><small><?= $item[1] ?></small></span><span class="status-pill off"><i></i>Не настроено</span><b>⌄</b></button>
                            <div class="spoiler-body"><div class="placeholder-box"><span>✦</span><h3>Функционал добавим после утверждения шаблона</h3><p>Структура блока уже подготовлена для будущих полей и настроек.</p><button class="button secondary" type="button" data-toast="Сейчас согласовываем только внешний вид">Показать пример действия</button></div></div>
                        </article>
                    <?php endforeach; ?>
                </section>

            <?php else: ?>
                <section class="page-title"><div><span class="eyebrow">ОБЩИЕ НАСТРОЙКИ</span><h1>Управление группой</h1><p>Настройка основной группы или канала для публикаций.</p></div><div class="section-badge violet">♟</div></section>
                <article class="panel group-panel"><div class="empty-state large"><div>♟</div><strong>Группа пока не добавлена</strong><p>Форма подключения будет добавлена после утверждения дизайна и логики.</p><button class="button primary" data-toast="Функционал добавления появится на следующем этапе">Добавить группу</button></div></article>
            <?php endif; ?>
        </div>
    </main>
</div>

<div class="modal" id="qrModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="qrTitle">
        <button class="modal-close" type="button" data-modal-close>×</button>
        <span class="step-label">ПОДКЛЮЧЕНИЕ</span><h2 id="qrTitle">Отсканируйте QR-код</h2><p>Откройте Telegram → Настройки → Устройства → Подключить устройство.</p>
        <div class="qr-placeholder"><div class="qr-grid"><?php for ($i=0;$i<81;$i++): ?><i class="<?= in_array(($i * 7 + $i % 5) % 11, [0,1,3,7], true) ? 'dark' : '' ?>"></i><?php endfor; ?></div><span class="qr-logo">✈</span></div>
        <div class="qr-status"><span class="status-dot pending"></span><div><strong>Ожидаем сканирование</strong><small>Код обновляется автоматически</small></div></div>
        <button class="button ghost full-button" type="button" data-modal-close>Отмена</button>
    </div>
</div>

<div class="modal" id="deleteModal" aria-hidden="true"><div class="modal-backdrop" data-modal-close></div><div class="modal-card compact"><button class="modal-close" data-modal-close>×</button><div class="warning-icon">!</div><h2>Удалить аккаунт?</h2><p>Это демонстрационное окно подтверждения. На этапе функционала действие будет необратимым.</p><div class="modal-actions"><button class="button ghost" data-modal-close>Отмена</button><button class="button danger" data-delete>Удалить</button></div></div></div>
<div class="toast-stack" id="toasts" aria-live="polite"></div>
<script src="assets/app.js?v=1"></script>
</body>
</html>
