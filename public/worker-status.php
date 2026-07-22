<?php
declare(strict_types=1);

use SkyGuardian\Worker\WorkerStatusService;

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
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$reply = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
};

if (($_SESSION['admin_authenticated'] ?? false) !== true) {
    $reply(401, ['ok' => false, 'message' => 'Требуется авторизация.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    $reply(405, ['ok' => false, 'message' => 'Метод не поддерживается.']);
}

try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $service = new WorkerStatusService(dirname(__DIR__) . '/storage');
    $reply(200, ['ok' => true, 'data' => $service->overview()]);
} catch (Throwable $exception) {
    error_log('Worker status endpoint: ' . $exception::class . ': ' . $exception->getMessage());
    $reply(500, ['ok' => false, 'message' => 'Не удалось получить состояние worker-ов.']);
}
