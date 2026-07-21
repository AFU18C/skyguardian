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

if (($_SESSION['admin_authenticated'] ?? false) !== true) {
    $reply(401, ['ok' => false, 'message' => 'Требуется авторизация.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reply(405, ['ok' => false, 'message' => 'Метод не поддерживается.']);
}
$csrf = (string) ($_POST['_token'] ?? '');
if ($csrf === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    $reply(419, ['ok' => false, 'message' => 'Сессия устарела. Обновите страницу.']);
}

$worker = dirname(__DIR__) . '/bin/data-channel-worker.php';
if (!is_file($worker)) {
    $reply(503, ['ok' => false, 'message' => 'Worker каналов данных не установлен.']);
}

$command = '/usr/bin/php ' . escapeshellarg($worker) . ' >/dev/null 2>&1 &';
exec($command);
$reply(202, ['ok' => true, 'message' => 'Проверка каналов запущена.']);
