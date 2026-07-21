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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$storageDir = dirname(__DIR__) . '/storage';
$file = $storageDir . '/site-settings.json';
$allowed = ['radar', 'shield'];

$readTheme = static function () use ($file, $allowed): string {
    if (!is_file($file)) return 'radar';
    $data = json_decode((string) file_get_contents($file), true);
    $theme = is_array($data) ? (string) ($data['theme'] ?? '') : '';
    return in_array($theme, $allowed, true) ? $theme : 'radar';
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $reply(200, ['ok' => true, 'theme' => $readTheme()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reply(405, ['ok' => false, 'message' => 'Метод не поддерживается.']);
}

if (($_SESSION['admin_authenticated'] ?? false) !== true) {
    $reply(401, ['ok' => false, 'message' => 'Требуется авторизация.']);
}

$token = (string) ($_POST['_token'] ?? '');
if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    $reply(419, ['ok' => false, 'message' => 'Сессия устарела. Обновите страницу.']);
}

$theme = trim((string) ($_POST['theme'] ?? ''));
if (!in_array($theme, $allowed, true)) {
    $reply(422, ['ok' => false, 'message' => 'Выберите доступный шаблон.']);
}

if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
    $reply(503, ['ok' => false, 'message' => 'Не удалось подготовить хранилище.']);
}

$payload = json_encode(['theme' => $theme, 'updated_at' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$temp = tempnam($storageDir, '.site-settings-');
if ($temp === false || file_put_contents($temp, $payload . PHP_EOL, LOCK_EX) === false || !rename($temp, $file)) {
    if (is_string($temp) && is_file($temp)) @unlink($temp);
    $reply(503, ['ok' => false, 'message' => 'Не удалось сохранить шаблон.']);
}
chmod($file, 0600);
$reply(200, ['ok' => true, 'theme' => $theme, 'message' => 'Шаблон сайта сохранён.']);
