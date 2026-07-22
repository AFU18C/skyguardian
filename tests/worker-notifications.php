<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SkyGuardian\Notification\WorkerAlertService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$dir = sys_get_temp_dir() . '/skyguardian-notifications-' . bin2hex(random_bytes(5));
mkdir($dir, 0770, true);
file_put_contents($dir . '/worker-notifications.json', json_encode([
    'enabled' => true,
    'bot_token' => '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef',
    'chat_id' => '-1001234567890',
], JSON_THROW_ON_ERROR));

$service = new WorkerAlertService($dir, cooldownSeconds: 900);
$sent = [];
$sender = static function (string $token, string $chatId, string $text) use (&$sent): void {
    $sent[] = compact('token', 'chatId', 'text');
};

$first = $service->notify('news', 'critical', 'network timeout token=secret-value', $sender, 1000);
$assert($first['sent'] === true, 'first critical notification must be sent');
$assert(count($sent) === 1, 'sender must be called once');
$assert(str_contains($sent[0]['text'], 'SkyGuardian'), 'notification must identify SkyGuardian');
$assert(!str_contains($sent[0]['text'], 'secret-value'), 'notification must redact secrets');

$duplicate = $service->notify('news', 'critical', 'network timeout token=secret-value', $sender, 1100);
$assert($duplicate['sent'] === false && $duplicate['reason'] === 'cooldown', 'duplicate notification must be suppressed');
$assert(count($sent) === 1, 'suppressed notification must not call sender');

$afterCooldown = $service->notify('news', 'critical', 'network timeout token=secret-value', $sender, 2000);
$assert($afterCooldown['sent'] === true, 'notification must be sent after cooldown');
$assert(count($sent) === 2, 'sender must be called after cooldown');

$recovery = $service->notify('news', 'recovery', 'Worker recovered', $sender, 2001);
$assert($recovery['sent'] === true, 'recovery notification must be sent');
$assert(count($sent) === 3, 'recovery must call sender');

$journal = $service->journal();
$assert(count($journal) === 4, 'journal must contain sent and suppressed attempts');
$assert($journal[0]['severity'] === 'recovery', 'journal must return newest item first');
$assert($journal[2]['delivery'] === 'suppressed', 'journal must record cooldown suppression');

$failed = $service->notify('alerts', 'critical', 'FLOOD_WAIT_10', static function (): void {
    throw new RuntimeException('bot token=very-secret');
}, 3000);
$assert($failed['sent'] === false && $failed['reason'] === 'delivery_failed', 'delivery failure must be reported without throwing');
$latest = $service->journal(1)[0];
$assert($latest['delivery'] === 'failed', 'failed delivery must be journaled');
$assert(!str_contains($latest['message'], 'very-secret'), 'delivery errors must be redacted');

$disabledDir = $dir . '/disabled';
mkdir($disabledDir, 0770, true);
file_put_contents($disabledDir . '/worker-notifications.json', json_encode(['enabled' => false], JSON_THROW_ON_ERROR));
$disabled = (new WorkerAlertService($disabledDir))->notify('news', 'warning', 'stale', $sender, 4000);
$assert($disabled['reason'] === 'disabled', 'disabled notifications must not be delivered');

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) $remove($path . '/' . $item);
        rmdir($path);
    } elseif (is_file($path)) unlink($path);
};
$remove($dir);

echo "Worker notification tests passed\n";
