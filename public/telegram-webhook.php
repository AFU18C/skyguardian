<?php
declare(strict_types=1);

require __DIR__ . '/TelegramAutomation.php';

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
// Query-string lookup remains as a compatibility fallback for webhooks that
// were registered by older SkyGuardian versions. New registrations use only
// Telegram's secret-token header so the secret is not written to access logs.
$lookupSecret = $headerSecret !== '' ? $headerSecret : trim((string) ($_GET['key'] ?? ''));
$automation = new TelegramAutomation(dirname(__DIR__) . '/storage');
$config = $lookupSecret !== '' ? $automation->findBySecret($lookupSecret) : null;

if (!is_array($config) || $headerSecret === '' || !hash_equals((string) ($config['secret'] ?? ''), $headerSecret)) {
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

try {
    $automation->runMaintenance();
    $automation->process($config, $update);
    echo '{"ok":true}';
} catch (Throwable $exception) {
    error_log('SkyGuardian Telegram webhook: ' . $exception->getMessage());
    http_response_code(500);
    echo '{"ok":false}';
}
