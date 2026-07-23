<?php
declare(strict_types=1);

use SkyGuardian\Worker\TelegramBotNotifier;

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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");

$reply = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SESSION['admin_authenticated'] ?? false) !== true) {
    $reply(401, ['ok' => false, 'message' => 'Требуется авторизация.']);
}

$projectDir = dirname(__DIR__);
$storageDir = $projectDir . '/storage';
$configFile = $storageDir . '/worker-notifications.json';
$journalFile = $storageDir . '/worker-notification-journal.json';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
    $reply(503, ['ok' => false, 'message' => 'Хранилище недоступно.']);
}

$lockHandle = @fopen($storageDir . '/worker-notifications.lock', 'c+');
$lockMode = $method === 'GET' ? LOCK_SH : LOCK_EX;
if ($lockHandle === false || !flock($lockHandle, $lockMode)) {
    if (is_resource($lockHandle)) fclose($lockHandle);
    $reply(503, ['ok' => false, 'message' => 'Настройки временно заняты.']);
}
register_shutdown_function(static function () use ($lockHandle): void {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

$readJson = static function (string $path): array {
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
};

$writeJson = static function (string $path, array $data) use ($storageDir): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temp = tempnam($storageDir, '.worker-notifications-');
    if ($temp === false) throw new RuntimeException('Не удалось создать временный файл.');
    try {
        if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Не удалось записать настройки.');
        chmod($temp, 0600);
        if (!rename($temp, $path)) throw new RuntimeException('Не удалось сохранить настройки.');
        chmod($path, 0600);
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
};

$publicConfig = static function (array $config): array {
    $chatId = trim((string) ($config['chat_id'] ?? ''));
    $token = trim((string) ($config['bot_token'] ?? ''));
    $maskedChatId = $chatId;
    if (strlen($chatId) > 6) {
        $maskedChatId = substr($chatId, 0, 4) . '…' . substr($chatId, -3);
    }

    return [
        'enabled' => (bool) ($config['enabled'] ?? false),
        'configured' => $token !== '' && $chatId !== '',
        'has_bot_token' => $token !== '',
        'chat_id' => $chatId,
        'chat_id_masked' => $maskedChatId,
        'cooldown_seconds' => max(60, (int) ($config['cooldown_seconds'] ?? 900)),
    ];
};

$config = $readJson($configFile);

if ($method === 'GET') {
    $journal = array_values(array_filter($readJson($journalFile), 'is_array'));
    $journal = array_reverse(array_slice($journal, -100));
    $reply(200, [
        'ok' => true,
        'config' => $publicConfig($config),
        'journal' => $journal,
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    $reply(405, ['ok' => false, 'message' => 'Метод не поддерживается.']);
}

if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
    $reply(419, ['ok' => false, 'message' => 'Сессия устарела. Обновите страницу.']);
}

$operation = trim((string) ($_POST['operation'] ?? 'save'));
$tokenInput = trim((string) ($_POST['bot_token'] ?? ''));
$chatId = trim((string) ($_POST['chat_id'] ?? ($config['chat_id'] ?? '')));
$token = $tokenInput !== '' ? $tokenInput : trim((string) ($config['bot_token'] ?? ''));

if ($operation === 'save' || $operation === 'test') {
    if (!preg_match('/^\d{6,12}:[A-Za-z0-9_-]{30,}$/', $token)) {
        $reply(422, ['ok' => false, 'message' => 'Укажите корректный Bot Token.']);
    }
    if (!preg_match('/^-?\d+$/', $chatId)) {
        $reply(422, ['ok' => false, 'message' => 'Укажите корректный Chat ID.']);
    }
}

try {
    if ($operation === 'test') {
        $autoload = $projectDir . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('Composer autoload is unavailable.');
        }
        require_once $autoload;
        if (!class_exists(TelegramBotNotifier::class)) {
            throw new RuntimeException('Telegram notifier is unavailable.');
        }
        $notifier = new TelegramBotNotifier($token, $chatId);
        $notifier->send("✅ SkyGuardian\nТестовое уведомление доставлено успешно.");
        $reply(200, ['ok' => true, 'message' => 'Тестовое уведомление отправлено.']);
    }

    if ($operation !== 'save') {
        $reply(422, ['ok' => false, 'message' => 'Неизвестная операция.']);
    }

    $saved = [
        'enabled' => filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOL),
        'bot_token' => $token,
        'chat_id' => $chatId,
        'cooldown_seconds' => max(60, min(86400, (int) ($_POST['cooldown_seconds'] ?? 900))),
        'updated_at' => gmdate(DATE_ATOM),
    ];
    $writeJson($configFile, $saved);
    $reply(200, [
        'ok' => true,
        'message' => $saved['enabled'] ? 'Уведомления включены.' : 'Настройки сохранены, уведомления выключены.',
        'config' => $publicConfig($saved),
    ]);
} catch (Throwable $exception) {
    error_log('Worker notification settings error: ' . $exception::class . ': ' . $exception->getMessage());
    $reply(503, ['ok' => false, 'message' => 'Не удалось выполнить операцию с уведомлениями.']);
}
