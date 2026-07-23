<?php
declare(strict_types=1);

require __DIR__ . '/TelegramAutomation.php';
require __DIR__ . '/TelegramRuntimeLock.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo '{"ok":false}';
    exit;
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 2 * 1024 * 1024) {
    http_response_code(413);
    echo '{"ok":false}';
    exit;
}

$headerSecret = trim((string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
if ($headerSecret === '') {
    http_response_code(403);
    echo '{"ok":false}';
    exit;
}

$storageDir = dirname(__DIR__) . '/storage';
$automation = new TelegramAutomation($storageDir);
$config = $automation->findBySecret($headerSecret);

if (!is_array($config) || !hash_equals((string) ($config['secret'] ?? ''), $headerSecret)) {
    http_response_code(403);
    echo '{"ok":false}';
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024 + 1);
if ($raw === false || strlen($raw) > 2 * 1024 * 1024) {
    http_response_code(413);
    echo '{"ok":false}';
    exit;
}

try {
    $update = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    http_response_code(400);
    echo '{"ok":false}';
    exit;
}

if (!is_array($update)) {
    http_response_code(400);
    echo '{"ok":false}';
    exit;
}

$runtimeLock = null;
try {
    $runtimeLock = TelegramRuntimeLock::acquire($storageDir);
    $automation->runMaintenance();
    $automation->process($config, $update);
    echo '{"ok":true}';
} catch (Throwable $exception) {
    error_log('SkyGuardian Telegram webhook: ' . $exception->getMessage());
    http_response_code(500);
    echo '{"ok":false}';
} finally {
    $runtimeLock?->release();
}
