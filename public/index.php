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

if ($action === 'telegram-check' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $reply = static function (int $status, array $payload): never {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    if (!$isAuthenticated) {
        $reply(401, ['ok' => false, 'message' => 'Требуется авторизация.']);
    }

    $csrfToken = (string) ($_POST['_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
        $reply(419, ['ok' => false, 'message' => 'Сессия устарела. Обновите страницу.']);
    }

    $botToken = trim((string) ($_POST['bot_token'] ?? ''));
    $chatId = trim((string) ($_POST['chat_id'] ?? ''));

    if (!preg_match('/^\\d{6,12}:[A-Za-z0-9_-]{30,}$/', $botToken)) {
        $reply(422, ['ok' => false, 'message' => 'Токен бота имеет неверный формат.']);
    }
    if (!preg_match('/^-?\\d+$/', $chatId)) {
        $reply(422, ['ok' => false, 'message' => 'Telegram Chat ID имеет неверный формат.']);
    }
    if (!function_exists('curl_init')) {
        $reply(503, ['ok' => false, 'message' => 'На сервере не установлено расширение PHP cURL.']);
    }

    $telegramRequest = static function (string $method, array $parameters = []) use ($botToken): mixed {
        $handle = curl_init('https://api.telegram.org/bot' . $botToken . '/' . $method);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($parameters),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($handle);
        $curlError = curl_error($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false || $curlError !== '') {
            throw new RuntimeException('Telegram недоступен: проверьте интернет-соединение VPS.');
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Telegram вернул некорректный ответ.');
        }
        if ($httpCode >= 400 || ($data['ok'] ?? false) !== true) {
            $description = trim((string) ($data['description'] ?? 'Telegram отклонил запрос.'));
            throw new RuntimeException($description);
        }

        return $data['result'] ?? null;
    };

    try {
        $bot = $telegramRequest('getMe');
        $chat = $telegramRequest('getChat', ['chat_id' => $chatId]);
        $membership = $telegramRequest('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => (string) ($bot['id'] ?? ''),
        ]);

        $memberCount = null;
        try {
            $countResult = $telegramRequest('getChatMemberCount', ['chat_id' => $chatId]);
            $memberCount = is_int($countResult) ? $countResult : (is_numeric($countResult) ? (int) $countResult : null);
        } catch (Throwable) {
            $memberCount = null;
        }

        $status = (string) ($membership['status'] ?? 'unknown');
        $isAdministrator = in_array($status, ['administrator', 'creator'], true);
        $rights = [];
        foreach ([
            'can_manage_chat', 'can_delete_messages', 'can_manage_video_chats',
            'can_restrict_members', 'can_promote_members', 'can_change_info',
            'can_invite_users', 'can_post_messages', 'can_edit_messages',
            'can_pin_messages', 'can_manage_topics',
        ] as $right) {
            $rights[$right] = $status === 'creator' || (($membership[$right] ?? false) === true);
        }

        $typeLabels = [
            'private' => 'Личный чат',
            'group' => 'Группа',
            'supergroup' => 'Супергруппа',
            'channel' => 'Канал',
        ];
        $chatType = (string) ($chat['type'] ?? 'unknown');
        $title = trim((string) ($chat['title'] ?? ''));
        if ($title === '') {
            $title = trim((string) (($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '')));
        }

        $reply(200, [
            'ok' => true,
            'message' => $isAdministrator ? 'Бот подключён и имеет права администратора.' : 'Бот видит чат, но не является администратором.',
            'bot' => [
                'id' => (string) ($bot['id'] ?? ''),
                'username' => (string) ($bot['username'] ?? ''),
                'name' => trim((string) (($bot['first_name'] ?? '') . ' ' . ($bot['last_name'] ?? ''))),
            ],
            'chat' => [
                'id' => (string) ($chat['id'] ?? $chatId),
                'title' => $title !== '' ? $title : 'Без названия',
                'type' => $chatType,
                'type_label' => $typeLabels[$chatType] ?? $chatType,
                'username' => (string) ($chat['username'] ?? ''),
                'member_count' => $memberCount,
            ],
            'membership' => [
                'status' => $status,
                'is_administrator' => $isAdministrator,
                'rights' => $rights,
            ],
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv')))->format('d.m.Y в H:i:s'),
        ]);
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
        if (str_contains(strtolower($message), 'unauthorized')) {
            $message = 'Токен бота недействителен.';
        } elseif (str_contains(strtolower($message), 'chat not found')) {
            $message = 'Чат не найден. Проверьте Chat ID и добавьте бота в чат.';
        }
        $reply(422, ['ok' => false, 'message' => $message]);
    } catch (Throwable) {
        $reply(503, ['ok' => false, 'message' => 'Не удалось проверить подключение Telegram.']);
    }
}

if ($action === 'backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$isAuthenticated) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Требуется авторизация.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $token = (string) ($_POST['_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'message' => 'Сессия устарела. Обновите страницу.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $projectRoot = dirname(__DIR__);
    $backupDir = $projectRoot . '/storage/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'message' => 'Не удалось создать папку резервных копий.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $createdAt = new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
    $backupPath = $backupDir . '/skyguardian-' . $createdAt->format('Ymd-His') . '.tar.gz';
    $command = 'tar -czf ' . escapeshellarg($backupPath)
        . ' --exclude=' . escapeshellarg('./storage/backups')
        . ' --exclude=' . escapeshellarg('./.git')
        . ' -C ' . escapeshellarg($projectRoot) . ' . 2>&1';
    exec($command, $backupOutput, $backupCode);

    if ($backupCode !== 0 || !is_file($backupPath) || filesize($backupPath) === 0) {
        if (is_file($backupPath)) {
            @unlink($backupPath);
        }
        http_response_code(503);
        echo json_encode(['ok' => false, 'message' => 'Не удалось создать резервную копию.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Резервная копия создана.',
        'created_at' => $createdAt->format('d.m.Y в H:i'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'reboot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$isAuthenticated) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Требуется авторизация.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $token = (string) ($_POST['_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'message' => 'Сессия устарела. Обновите страницу.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    session_write_close();
    exec('sudo -n /usr/bin/systemctl reboot --no-block 2>&1', $rebootOutput, $rebootCode);

    if ($rebootCode !== 0) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'message' => 'Сервер не разрешил перезагрузку. Проверьте права службы.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(202);
    echo json_encode(['ok' => true, 'message' => 'Перезагрузка VPS запущена.'], JSON_UNESCAPED_UNICODE);
    exit;
}

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
    <link rel="stylesheet" href="/assets/app.css?v=25">
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
    'alerts-settings', 'group', 'site',
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
    'site' => ['Управление сайтом', 'Общие настройки'],
];

[$title, $section] = $pageMeta[$page];
$isSources = str_ends_with($page, '-sources');
$isSettings = str_ends_with($page, '-settings');
$isAlerts = str_starts_with($page, 'alerts-');
$accent = $isAlerts ? 'red' : 'blue';

function serverMetrics(): array
{
    $cpuCount = 1;
    if (is_readable('/proc/cpuinfo')) {
        $cpuInfo = (string) file_get_contents('/proc/cpuinfo');
        $detected = preg_match_all('/^processor\s*:/m', $cpuInfo);
        $cpuCount = max(1, (int) $detected);
    }

    $load = sys_getloadavg();
    $cpu = is_array($load) ? min(100, max(0, (($load[0] ?? 0) / $cpuCount) * 100)) : 0;

    $memory = 0.0;
    if (is_readable('/proc/meminfo')) {
        $memoryInfo = (string) file_get_contents('/proc/meminfo');
        preg_match('/^MemTotal:\s+(\d+)/m', $memoryInfo, $totalMatch);
        preg_match('/^MemAvailable:\s+(\d+)/m', $memoryInfo, $availableMatch);
        $total = (int) ($totalMatch[1] ?? 0);
        $available = (int) ($availableMatch[1] ?? 0);
        if ($total > 0) {
            $memory = (($total - $available) / $total) * 100;
        }
    }

    $diskTotal = @disk_total_space('/');
    $diskFree = @disk_free_space('/');
    $disk = ($diskTotal && $diskFree !== false) ? (($diskTotal - $diskFree) / $diskTotal) * 100 : 0;

    $uptime = 'Недоступно';
    if (is_readable('/proc/uptime')) {
        $seconds = (int) floor((float) explode(' ', trim((string) file_get_contents('/proc/uptime')))[0]);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $uptime = $days > 0 ? $days . ' д. ' . $hours . ' ч.' : $hours . ' ч. ' . intdiv($seconds % 3600, 60) . ' мин.';
    }

    $highest = max($cpu, $memory, $disk);
    $level = $highest >= 90 ? 'critical' : ($highest >= 75 ? 'warning' : 'normal');
    $labels = ['normal' => 'Нагрузка в норме', 'warning' => 'Высокая нагрузка', 'critical' => 'Критическая нагрузка'];

    return [
        'cpu' => (int) round($cpu),
        'memory' => (int) round($memory),
        'disk' => (int) round($disk),
        'uptime' => $uptime,
        'level' => $level,
        'label' => $labels[$level],
    ];
}

function latestBackup(): array
{
    $files = glob(dirname(__DIR__) . '/storage/backups/skyguardian-*.tar.gz') ?: [];
    $latest = null;
    $latestTime = 0;

    foreach ($files as $file) {
        $modified = (int) @filemtime($file);
        if ($modified > $latestTime && @filesize($file) > 0) {
            $latest = $file;
            $latestTime = $modified;
        }
    }

    if ($latest === null) {
        return ['display' => 'Ещё не создан', 'exists' => false];
    }

    $date = (new DateTimeImmutable('@' . $latestTime))->setTimezone(new DateTimeZone('Europe/Kyiv'));
    return ['display' => $date->format('d.m.Y в H:i'), 'exists' => true];
}

$serverMetrics = serverMetrics();
$latestBackup = latestBackup();

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
    <link rel="stylesheet" href="assets/app.css?v=25">
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
            <a class="nav-link<?= active($page, 'site') ?>" href="?page=site"><span class="nav-icon">◎</span><span>Управление сайтом</span></a>
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
                <section class="page-title home-title">
                    <div>
                        <span class="eyebrow">ГЛАВНАЯ</span>
                        <h1>Состояние системы</h1>
                        <p>Актуальные показатели сервера SkyGuardian.</p>
                    </div>
                    <div class="server-health <?= htmlspecialchars($serverMetrics['level'], ENT_QUOTES, 'UTF-8') ?>">
                        <i></i><span><?= htmlspecialchars($serverMetrics['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </section>

                <article class="panel server-load-panel">

                    <div class="server-metrics">
                        <?php foreach ([
                            ['cpu', 'Процессор', 'Загрузка вычислительных ресурсов', '⌁'],
                            ['memory', 'Оперативная память', 'Использовано доступной памяти', '▦'],
                            ['disk', 'Дисковое пространство', 'Использовано хранилища', '◫'],
                        ] as [$key, $metricTitle, $description, $icon]): $value = $serverMetrics[$key]; $metricLevel = $value >= 90 ? 'critical' : ($value >= 75 ? 'warning' : 'normal'); ?>
                            <section class="server-metric <?= $metricLevel ?>">
                                <div class="metric-top"><span class="metric-icon"><?= $icon ?></span><span class="metric-value"><?= $value ?><small>%</small></span></div>
                                <strong><?= htmlspecialchars($metricTitle, ENT_QUOTES, 'UTF-8') ?></strong>
                                <p><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
                                <div class="metric-track"><i style="width:<?= $value ?>%"></i></div>
                            </section>
                        <?php endforeach; ?>
                        <section class="server-metric uptime">
                            <div class="metric-top"><span class="metric-icon">◷</span><span class="uptime-value"><?= htmlspecialchars($serverMetrics['uptime'], ENT_QUOTES, 'UTF-8') ?></span></div>
                            <strong>Время работы</strong>
                            <p>Без перезапуска сервера</p>
                            
                        </section>
                        <section class="server-metric backup-metric">
                            <div class="metric-top"><span class="metric-icon">⇩</span><span class="backup-state<?= $latestBackup['exists'] ? ' ready' : '' ?>" data-backup-state><?= $latestBackup['exists'] ? 'Сохранён' : 'Нет копии' ?></span></div>
                            <strong>Последний бэкап</strong>
                            <p data-backup-time><?= htmlspecialchars($latestBackup['display'], ENT_QUOTES, 'UTF-8') ?></p>
                            <button class="button primary backup-button" type="button" data-backup-create data-csrf="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <span aria-hidden="true">＋</span>Сделать бэкап
                            </button>
                        </section>
                    </div>
                    <div class="server-reboot-row">
                        <button class="button danger server-reboot-button" type="button" data-reboot-open>
                            <span aria-hidden="true">↻</span>Перезагрузить VPS
                        </button>
                    </div>
                </article>

            <?php elseif ($isSources): ?>
                <section class="page-title"><div><span class="eyebrow <?= $accent ?>"><?= $isAlerts ? 'ВОЗДУШНАЯ ТРЕВОГА' : 'НОВОСТИ' ?></span><h1>Каналы данных</h1></div><button class="button primary add-connection-button" type="button" data-tooltip="Добавить канал данных" aria-label="Добавить канал данных" data-add-source>Добавить</button></section>

                <article class="panel data-channels-panel" data-source-list data-source-scope="<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="empty-state" data-source-empty><div>◇</div><strong>Каналов данных пока нет</strong></div>
                </article>

            <?php elseif ($isSettings): ?>
                <section class="page-title"><div><span class="eyebrow <?= $accent ?>"><?= $isAlerts ? 'ВОЗДУШНАЯ ТРЕВОГА' : 'НОВОСТИ' ?></span><h1>Настройка</h1></div><button class="button primary add-connection-button" type="button" data-tooltip="Технический аккаунт и API" aria-label="Добавить технический аккаунт и API" data-add-connection>Добавить</button></section>

                <article class="panel technical-accounts-panel" data-tech-list data-tech-scope="<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="empty-state" data-tech-empty><div>◇</div><strong>Технических аккаунтов пока нет</strong></div>
                </article>
            <?php elseif ($page === 'group'): ?>
                <section class="page-title">
                    <div><span class="eyebrow">ОБЩИЕ НАСТРОЙКИ</span><h1>Управление группой</h1></div>
                    <button class="button primary add-connection-button" type="button" data-add-group-channel>Добавить канал</button>
                </section>
                <article class="panel data-channels-panel" data-group-channel-list>
                    <div class="empty-state" data-group-channel-empty><div>◇</div><strong>Каналы пока не добавлены</strong></div>
                </article>
            <?php else: ?>
                <section class="page-title"><div><span class="eyebrow">ОБЩИЕ НАСТРОЙКИ</span><h1>Управление сайтом</h1><p>Настройка сайта SkyGuardian.</p></div><div class="section-badge violet">◎</div></section>
                <article class="panel group-panel"><div class="empty-state large"><div>◎</div><strong>Настройки сайта пока не добавлены</strong><p>Параметры управления сайтом будут добавлены на следующем этапе.</p></div></article>
            <?php endif; ?>
        </div>
    </main>
</div>

<div class="modal group-control-modal" id="groupControlModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card group-control-card" role="dialog" aria-modal="true" aria-labelledby="groupControlTitle">
        <button class="modal-close" type="button" data-modal-close aria-label="Закрыть">×</button>
        <div class="group-control-heading">
            <span class="step-label">УПРАВЛЕНИЕ TELEGRAM</span>
            <h2 id="groupControlTitle" data-group-control-title>Канал или группа</h2>
            <p data-group-control-meta>Проверка подключения и доступные функции бота.</p>
        </div>
        <div class="group-control-layout">
            <nav class="group-control-tabs" aria-label="Разделы управления">
                <button class="active" type="button" data-group-control-tab="overview">Обзор</button>
                <button type="button" data-group-control-tab="publications">Публикации</button>
                <button type="button" data-group-control-tab="messages">Сообщения</button>
                <button type="button" data-group-control-tab="members">Участники</button>
                <button type="button" data-group-control-tab="invites">Заявки и ссылки</button>
                <button type="button" data-group-control-tab="settings">Настройки</button>
                <button type="button" data-group-control-tab="automation">Автоматизация</button>
                <button type="button" data-group-control-tab="journal">Журнал</button>
            </nav>
            <div class="group-control-content">
                <section class="group-control-pane active" data-group-control-pane="overview">
                    <div class="control-status-card" data-telegram-status>
                        <div><span class="control-status-dot"></span><div><strong data-telegram-status-title>Подключение не проверено</strong><small data-telegram-status-text>Нажмите кнопку, чтобы проверить бота и его права</small></div></div>
                        <div class="telegram-status-actions">
                            <button class="button secondary" type="button" data-group-action="info" hidden>Информация</button>
                            <button class="button primary" type="button" data-group-action="check" data-csrf="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">Проверить подключение</button>
                        </div>
                    </div>
                    <div class="telegram-check-details" data-telegram-details hidden>
                        <article><small>Бот</small><strong data-telegram-bot>—</strong></article>
                        <article><small>Чат</small><strong data-telegram-chat>—</strong></article>
                        <article><small>Тип</small><strong data-telegram-type>—</strong></article>
                        <article><small>Участники</small><strong data-telegram-members>—</strong></article>
                        <article class="telegram-rights-card"><small>Права бота</small><div data-telegram-rights></div></article>
                        <small class="telegram-checked-at" data-telegram-checked-at></small>
                    </div>
                    <div class="control-feature-grid">
                        <article><span>✎</span><strong>Публикации</strong><small>Текст, медиа, файлы, опросы, закрепление и тихая отправка</small></article>
                        <article><span>☷</span><strong>Сообщения</strong><small>Просмотр доступных сообщений, удаление и закрепление</small></article>
                        <article><span>♟</span><strong>Модерация</strong><small>Ограничение, блокировка, разблокировка и права администраторов</small></article>
                        <article><span>↗</span><strong>Приглашения</strong><small>Заявки, временные ссылки, лимиты входов и отзыв ссылок</small></article>
                        <article><span>⚙</span><strong>Настройки чата</strong><small>Название, описание, фото, разрешения, темы и реакции</small></article>
                        <article><span>✦</span><strong>Автоматизация</strong><small>Антиспам, запрещённые слова, приветствие и автоудаление</small></article>
                    </div>
                </section>
                <section class="group-control-pane" data-group-control-pane="publications"><h3>Публикации</h3><p>Отправка текста, фото, видео, файлов и опросов; предпросмотр, закрепление, тихая отправка и планирование.</p></section>
                <section class="group-control-pane" data-group-control-pane="messages"><h3>Сообщения</h3><p>Последние доступные боту сообщения, поиск, удаление, закрепление, открепление и управление реакциями.</p></section>
                <section class="group-control-pane" data-group-control-pane="members"><h3>Участники</h3><p>Поиск по Telegram ID, предупреждения, временные ограничения, блокировка, разблокировка и управление администраторами.</p></section>
                <section class="group-control-pane" data-group-control-pane="invites"><h3>Заявки и приглашения</h3><p>Принятие и отклонение заявок, создание временных и постоянных ссылок, лимиты и отзыв приглашений.</p></section>
                <section class="group-control-pane" data-group-control-pane="settings"><h3>Настройки группы или канала</h3><p>Название, описание, фотография, права участников, реакции, темы форума и разрешённые типы сообщений.</p></section>
                <section class="group-control-pane" data-group-control-pane="automation"><h3>Автоматизация и защита</h3><p>Антиспам, фильтр ссылок и слов, капча, приветствие, автоудаление и уведомления администратору.</p></section>
                <section class="group-control-pane" data-group-control-pane="journal"><h3>Журнал действий</h3><p>История команд, действий модерации, публикаций, ошибок и изменений настроек.</p></section>
            </div>
        </div>
    </div>
</div>

<div class="modal group-channel-modal" id="groupChannelModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card source-modal-card" role="dialog" aria-modal="true" aria-labelledby="groupChannelTitle">
        <button class="modal-close" type="button" data-modal-close aria-label="Закрыть">×</button>
        <span class="step-label" data-group-channel-modal-label>ДОБАВЛЕНИЕ КАНАЛА</span>
        <h2 id="groupChannelTitle">Канал или группа Telegram</h2>
        <p>Укажите данные бота и Telegram ID канала или группы.</p>
        <form class="form-grid source-form" data-group-channel-form>
            <input type="hidden" name="group_channel_id" value="">
            <label class="full"><span>Название канала *</span><input name="name" placeholder="Например: Основной канал" required></label>
            <label class="full"><span>Ссылка *</span><input name="link" type="url" inputmode="url" placeholder="https://t.me/channel_name" required></label>
            <label class="full"><span>Telegram Chat ID *</span><input name="chat_id" inputmode="text" placeholder="-1001234567890" required></label>
            <label class="full"><span>Токен бота *</span><div class="input-action"><input name="bot_token" type="password" autocomplete="new-password" placeholder="123456789:AA..." required><button type="button" data-password aria-label="Показать или скрыть токен">◉</button></div><small class="form-hint" data-group-token-hint>Токен хранится скрыто и отображается в списке только частично.</small></label>
            <label class="full"><span>ID администратора *</span><input name="admin_id" inputmode="numeric" placeholder="123456789" required></label>
            <div class="form-actions source-form-actions full">
                <button class="button danger" type="button" data-group-channel-delete hidden>Удалить</button>
                <button class="button primary" type="submit" data-group-channel-save>Добавить</button>
            </div>
        </form>
    </div>
</div>

<div class="modal source-modal" id="sourceModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card source-modal-card" role="dialog" aria-modal="true" aria-labelledby="sourceTitle">
        <button class="modal-close" type="button" data-modal-close aria-label="Закрыть">×</button>
        <span class="step-label" data-source-modal-label>ДОБАВЛЕНИЕ КАНАЛА</span>
        <h2 id="sourceTitle">Канал данных</h2>
        <p>Укажите, откуда получать сообщения и куда их публиковать.</p>
        <form class="form-grid source-form" data-source-form>
            <input type="hidden" name="source_id" value="">
            <label class="full"><span>Название *</span><input name="name" placeholder="Например: Новости города" required></label>
            <label class="full"><span>Канал или группа — источник сообщений *</span><input name="source" placeholder="@source_channel или ссылка" required></label>
            <label class="full"><span>Технический аккаунт</span><select name="account"><option value="">Выберите аккаунт</option><option value="demo">Подключённый технический аккаунт</option></select></label>
            <label class="full"><span>Канал или группа для публикации *</span><input name="destination" placeholder="@destination_channel или ссылка" required></label>
            <label class="full"><span>Формат публикации *</span><select name="publication_format" required><option value="">Выберите формат публикации</option><option value="original">Оригинал полностью</option><option value="text">Только текст</option><option value="text_without_links">Только текст без ссылок</option><option value="media">Только медиа</option><option value="text_and_media">Текст и медиа</option></select></label>
            <label class="full"><span>Частота проверки *</span><div class="frequency-control"><input name="check_frequency" type="number" inputmode="numeric" min="3" max="86400" step="1" placeholder="Введите частоту" required data-frequency-value><select name="check_frequency_unit" aria-label="Единица частоты проверки" required data-frequency-unit><option value="seconds">Секунды</option><option value="hours">Часы</option></select></div></label>
            <label class="full"><span>Начало обработки *</span><select name="processing_start" required><option value="">Выберите начало обработки</option><option value="new">Только новые сообщения</option><option value="last_5">Последние 5 сообщений</option><option value="last_10">Последние 10 сообщений</option><option value="last_20">Последние 20 сообщений</option></select></label>
            <label class="full"><span>Ключевые слова</span><textarea name="keywords" rows="3" placeholder="Например: тревога, ракета, беспилотник"></textarea><small class="form-hint">Слова и фразы через запятую. Оставьте пустым, чтобы публиковать все сообщения.</small></label>
            <label class="full"><span>Стоп-слова</span><textarea name="stop_words" rows="3" placeholder="Например: реклама, розыгрыш"></textarea><small class="form-hint">Сообщения с этими словами публиковаться не будут.</small></label>
            <div class="custom-text-setting full">
                <div class="custom-text-head">
                    <div><strong>Добавлять собственный текст</strong><small>Ваш текст будет добавлен к каждой публикации</small></div>
                    <label class="switch" aria-label="Добавлять собственный текст"><input name="custom_text_enabled" type="checkbox" value="1" data-custom-text-toggle><span></span></label>
                </div>
                <div class="custom-text-editor" data-custom-text-editor hidden>
                    <label><span>Размещение текста</span><select name="custom_text_position" data-custom-text-position><option value="after">После сообщения</option><option value="before">Перед сообщением</option></select></label>
                    <label><span>Ваш текст</span>
                        <div class="editor-shell">
                            <div class="editor-toolbar" aria-label="Форматирование текста">
                                <button type="button" data-editor-wrap="**" title="Жирный"><strong>B</strong></button>
                                <button type="button" data-editor-wrap="__" title="Курсив"><em>I</em></button>
                                <button type="button" data-editor-link title="Добавить ссылку">↗</button>
                            </div>
                            <textarea name="custom_text" rows="5" maxlength="2000" placeholder="Введите текст, который будет добавляться к публикации" data-custom-text-input></textarea>
                        </div>
                    </label>
                    <button class="button ghost preview-toggle" type="button" data-custom-text-preview-button>Предпросмотр</button>
                    <div class="telegram-preview" data-custom-text-preview hidden>
                        <span class="preview-label">ПРЕДПРОСМОТР ПУБЛИКАЦИИ</span>
                        <div class="telegram-message" data-custom-text-preview-content></div>
                    </div>
                </div>
            </div>
            <div class="form-actions source-form-actions full">
                <button class="button danger" type="button" data-source-delete hidden>Удалить</button>
                <button class="button primary" type="submit" data-source-save>Добавить</button>
            </div>
        </form>
    </div>
</div>

<div class="modal connection-modal" id="connectionModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-card connection-modal-card" role="dialog" aria-modal="true" aria-labelledby="connectionTitle">
        <button class="modal-close" type="button" data-modal-close aria-label="Закрыть">×</button>
        <div class="connection-modal-heading"><span class="step-label" data-tech-modal-label>ДОБАВЛЕНИЕ ПОДКЛЮЧЕНИЯ</span><h2 id="connectionTitle">Технический аккаунт и API</h2><p>Укажите данные Telegram API и подключите технический аккаунт.</p></div>
                <input type="hidden" name="tech_account_id" value="" data-tech-account-id>
                <section class="workspace-grid">
                    <article class="panel api-panel">
                        <div class="panel-head"><div><span class="step-label">ШАГ 1</span><h2>Telegram API</h2><p>Данные приложения из my.telegram.org</p></div><span class="status-pill off"><i></i>Не настроено</span></div>
                        <form class="form-grid api-form" data-api-form>
                            <label class="full"><span>Название подключения</span><input name="name" placeholder="Например: Основной API"></label>
                            <label><span>API ID</span><input name="api_id" inputmode="numeric" placeholder="12345678"></label>
                            <label><span>API Hash</span><div class="input-action"><input name="api_hash" type="password" placeholder="Введите API Hash"><button type="button" data-password>◉</button></div></label>
                            <div class="form-actions full"><span class="form-hint">Все поля обязательны</span><button class="button secondary" type="button" data-api-check>Проверить API</button></div>
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
                            
                        </div>
                    </article>
                </section>
                <div class="form-actions connection-form-actions">
                    <button class="button danger" type="button" data-tech-delete hidden>Удалить</button>
                    <button class="button primary" type="button" data-tech-save>Сохранить</button>
                </div>
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
<div class="modal reboot-modal" id="rebootModal" aria-hidden="true">
    <div class="modal-card reboot-modal-card" role="dialog" aria-modal="true" aria-labelledby="rebootTitle">
        <button class="modal-close" type="button" data-modal-close aria-label="Закрыть">×</button>
        <span class="step-label danger-text">ОПАСНОЕ ДЕЙСТВИЕ</span>
        <h2 id="rebootTitle">Перезагрузить VPS?</h2>
        <p>Все процессы SkyGuardian временно остановятся. Сайт будет недоступен несколько минут.</p>
        <div class="reboot-warning"><strong>Полная перезагрузка сервера</strong><span>Несохранённые операции будут прерваны.</span></div>
        <div class="modal-actions">
            <button class="button ghost" type="button" data-modal-close>Отмена</button>
            <button class="button danger" type="button" data-reboot-confirm data-csrf="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">Перезагрузить</button>
        </div>
    </div>
</div>
<div class="toast-stack" id="toasts" aria-live="polite"></div>
<script src="assets/app.js?v=22"></script>
</body>
</html>
