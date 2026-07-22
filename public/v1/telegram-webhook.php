<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use SkyGuardian\Telegram\BotApiClient;
use SkyGuardian\Telegram\BotConfigRepository;
use SkyGuardian\Telegram\UpdateHandler;
use SkyGuardian\Telegram\WebhookVerifier;

$config = (new BotConfigRepository($store))->get();
if (!($config['enabled'] ?? false) || ($config['mode'] ?? 'webhook') !== 'webhook') {
    http_response_code(503);
    exit('Bot disabled');
}
$expected = (string) ($config['webhook_secret'] ?? '');
$urlSecret = (string) ($_GET['secret'] ?? '');
$headerSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null;
if (!(new WebhookVerifier())->verify($urlSecret, is_string($headerSecret) ? $headerSecret : null, $expected)) {
    http_response_code(403);
    exit('Forbidden');
}
$raw = file_get_contents('php://input');
$update = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($update)) {
    http_response_code(400);
    exit('Invalid update');
}
$handler = new UpdateHandler($store, new BotApiClient((string) $config['token']));
$handler->handle($update);
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
