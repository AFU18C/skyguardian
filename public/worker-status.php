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
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");

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
    $projectRoot = dirname(__DIR__);
    $autoload = $projectRoot . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    // Deployments may keep an older generated Composer autoloader after a
    // fast-forward update. Load the status service directly as a safe fallback.
    if (!class_exists(WorkerStatusService::class, false)) {
        $serviceFile = $projectRoot . '/src/Worker/WorkerStatusService.php';
        if (!is_file($serviceFile)) {
            throw new RuntimeException('WorkerStatusService.php is missing.');
        }
        require_once $serviceFile;
    }

    if (!class_exists(WorkerStatusService::class)) {
        throw new RuntimeException('WorkerStatusService is unavailable.');
    }

    $storageDir = $projectRoot . '/storage';
    if (!is_dir($storageDir) && !mkdir($storageDir, 0750, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Storage directory is unavailable.');
    }

    $service = new WorkerStatusService($storageDir);
    $reply(200, ['ok' => true, 'data' => $service->overview()]);
} catch (Throwable $exception) {
    error_log('Worker status endpoint: ' . $exception::class . ': ' . $exception->getMessage());
    $reply(500, ['ok' => false, 'message' => 'Не удалось получить состояние worker-ов.']);
}
