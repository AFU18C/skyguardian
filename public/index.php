<?php
declare(strict_types=1);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('skyguardian_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$storageDir = dirname(__DIR__) . '/storage';
$adminFile = $storageDir . '/admin.json';
$requestPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$requestedPage = $_GET['page'] ?? null;
$action = $_GET['action'] ?? null;
$isAuthenticated = ($_SESSION['admin_authenticated'] ?? false) === true;

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    header('Location: /?page=login');
    exit;
}

$isLoginRequest = $requestedPage === 'login' || in_array($requestPath, ['/admin', '/admin/login'], true);
$loginError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLoginRequest) {
    $token = (string) ($_POST['_token'] ?? '');
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $admin = is_file($adminFile) ? json_decode((string) file_get_contents($adminFile), true) : null;

    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        $loginError = 'Сессия устарела. Обновите страницу и попробуйте снова.';
    } elseif (!is_array($admin) || empty($admin['email']) || empty($admin['password_hash'])) {
        $loginError = 'Администратор ещё не создан. Выполните команду php artisan admin:create.';
    } elseif (!hash_equals(strtolower((string) $admin['email']), strtolower($email)) || !password_verify($password, (string) $admin['password_hash'])) {
        usleep(350000);
        $loginError = 'Неверная почта или пароль.';
    } else {
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_email'] = (string) $admin['email'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: /?page=home');
        exit;
    }
}

if (!$isAuthenticated && !$isLoginRequest && $requestedPage !== null) {
    header('Location: /?page=login');
    exit;
}

$isPublicLanding = $requestedPage === null && !$isLoginRequest;
if ($isAuthenticated && $isLoginRequest && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /?page=home');
    exit;
}

if ($isPublicLanding || $isLoginRequest) {
    $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
    $standaloneTitle = $isLoginRequest ? 'Вход для администратора' : 'Ведутся работы';
    ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070b15">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($standaloneTitle, ENT_QUOTES, 'UTF-8') ?> — SkyGuardian</title>
    <link rel="stylesheet" href="/assets/app.css?v=4">
</head>
<body class="standalone-page">
    <main class="standalone-shell">
        <a class="standalone-brand" href="/" aria-label="SkyGuardian — главная">
            <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2.7 20 6v5.6c0 5.1-3.4 8.1-8 9.7-4.6-1.6-8-4.6-8-9.7V6l8-3.3Zm0 4.1-4.2 1.7v3.2c0 2.8 1.6 4.7 4.2 5.9 2.6-1.2 4.2-3.1 4.2-5.9V8.5L12 6.8Z"/></svg></span>
            <span><strong>SkyGuardian</strong><small>CONTROL CENTER</small></span>
        </a>

        <?php if ($isLoginRequest): ?>
            <section class="login-card">
                <span class="standalone-kicker">ЗАЩИЩЁННЫЙ ДОСТУП</span>
                <h1>Вход в панель</h1>
                <p>Введите почту и пароль администратора.</p>
                <?php if ($loginError): ?><div class="login-error" role="alert"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <form class="login-form" method="post" action="/?page=login">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <label><span>Почта</span><input name="email" type="email" autocomplete="username" placeholder="admin@example.com" required autofocus></label>
                    <label><span>Пароль</span><input name="password" type="password" autocomplete="current-password" placeholder="Введите пароль" required></label>
                    <button class="button primary login-submit" type="submit">Войти</button>
                </form>
                <div class="login-note"><i></i><span>Доступ только для администратора</span></div>
            </section>
        <?php else: ?>
            <section class="maintenance-card">
                <div class="maintenance-icon" aria-hidden="true">✦</div>
                <span class="standalone-kicker">SKYGUARDIAN</span>
                <h1>Ведутся работы</h1>
                <p>Мы готовим систему к запуску. Пожалуйста, зайдите позже.</p>
                <div class="maintenance-status"><i></i><span>Система находится в разработке</span></div>
            </section>
            <a class="admin-entry" href="/?page=login">Вход для администратора</a>
        <?php endif; ?>
    </main>
</body>
</html>
    <?php
    exit;
}

if (!$isAuthenticated) {
    header('Location: /?page=login');
    exit;
}

$page = $requestedPage ?? 'home';
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
    <link rel="stylesheet" href="assets/app.css?v=7">
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
            <a class="nav-link logout" href="/?action=logout"><span class="nav-icon">↪</span><span>Выйти</span></a>
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
                <section class="page-title">
                    <div>
                        <span class="eyebrow">ГЛАВНАЯ</span>
                        <h1>Страница в разработке</h1>
                    </div>
                </section>

            <?php elseif ($isSources): ?>
                <section class="page-title"><div><span class="eyebrow <?= $accent ?>"><?= $isAlerts ? 'ВОЗДУШНАЯ ТРЕВОГА' : 'НОВОСТИ' ?></span><h1>Каналы данных</h1></div><button class="button primary add-connection-button" type="button" data-tooltip="Добавить канал данных" aria-label="Добавить канал данных" data-add-source>Добавить</button></section>

                <article class="panel data-channels-panel">
                    <div class="empty-state"><div>◇</div><strong>Каналов данных пока нет</strong></div>
                </article>

            <?php elseif ($isSettings): ?>
                <section class="page-title"><div><span class="eyebrow <?= $accent ?>"><?= $isAlerts ? 'ВОЗДУШНАЯ ТРЕВОГА' : 'НОВОСТИ' ?></span><h1>Настройка</h1></div><button class="button primary add-connection-button" type="button" data-tooltip="Технический аккаунт и API" aria-label="Добавить технический аккаунт и API" data-add-connection>Добавить</button></section>

                <article class="panel technical-accounts-panel">
                    <div class="empty-state"><div>◇</div><strong>Технических аккаунтов пока нет</strong></div>
                </article>
            <?php else: ?>
                <section class="page-title"><div><span class="eyebrow">ОБЩИЕ НАСТРОЙКИ</span><h1>Управление группой</h1><p>Настройка основной группы или канала для публикаций.</p></div><div class="section-badge violet">♟</div></section>
                <article class="panel group-panel"><div class="empty-state large"><div>♟</div><strong>Группа пока не добавлена</strong><p>Форма подключения будет добавлена после утверждения дизайна и логики.</p><button class="button primary" data-toast="Функционал добавления появится на следующем этапе">Добавить группу</button></div></article>
            <?php endif; ?>
        </div>
    </main>
</div>

<div class="modal source-modal" id="sourceModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card source-modal-card" role="dialog" aria-modal="true" aria-labelledby="sourceTitle">
        <button class="modal-close" type="button" data-modal-close aria-label="Закрыть">×</button>
        <span class="step-label">ДОБАВЛЕНИЕ КАНАЛА</span>
        <h2 id="sourceTitle">Канал данных</h2>
        <p>Укажите, откуда получать сообщения и куда их публиковать.</p>
        <form class="form-grid source-form" data-source-form>
            <label class="full"><span>Название</span><input name="name" placeholder="Например: Новости города" required></label>
            <label class="full"><span>Канал или группа — источник сообщений</span><input name="source" placeholder="@source_channel или ссылка" required></label>
            <label class="full"><span>Технический аккаунт</span><select name="account" required><option value="">Выберите аккаунт</option><option value="demo">Подключённый технический аккаунт</option></select></label>
            <label class="full"><span>Канал или группа для публикации</span><input name="destination" placeholder="@destination_channel или ссылка" required></label>
            <div class="form-actions full"><span class="form-hint">Все поля обязательны</span><button class="button primary" type="submit">Добавить</button></div>
        </form>
    </div>
</div>

<div class="modal connection-modal" id="connectionModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card connection-modal-card" role="dialog" aria-modal="true" aria-labelledby="connectionTitle">
        <button class="modal-close" type="button" data-modal-close aria-label="Закрыть">×</button>
        <div class="connection-modal-heading"><span class="step-label">ДОБАВЛЕНИЕ ПОДКЛЮЧЕНИЯ</span><h2 id="connectionTitle">Технический аккаунт и API</h2><p>Сначала сохраните данные Telegram API, затем подключите технический аккаунт.</p></div>
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
    </div>
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
<script src="assets/app.js?v=4"></script>
</body>
</html>
