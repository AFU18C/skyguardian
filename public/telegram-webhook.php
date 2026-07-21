<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/TelegramAutomation.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"ok":false}';
    exit;
}

$secret = trim((string)($_GET['key'] ?? ''));
$headerSecret = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
$automation = new TelegramAutomation(dirname(__DIR__) . '/storage');
$config = $automation->findBySecret($secret);

if (!is_array($config) || $secret === '' || $headerSecret === '' || !hash_equals($secret, $headerSecret)) {
    http_response_code(403);
    echo '{"ok":false}';
    exit;
}

$raw = file_get_contents('php://input');
$update = json_decode((string)$raw, true);
if (!is_array($update)) {
    http_response_code(400);
    echo '{"ok":false}';
    exit;
}

try {
    $automation->runMaintenance();
    $automation->process($config, $update);
    echo '{"ok":true}';
} catch (Throwable $exception) {
    error_log('SkyGuardian Telegram webhook: ' . $exception->getMessage());
    http_response_code(500);
    echo '{"ok":false}';
}
