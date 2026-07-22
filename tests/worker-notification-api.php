<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/public/worker-notifications.php');
$service = (string) file_get_contents($root . '/deploy/skyguardian-worker-notifications.service');
$timer = (string) file_get_contents($root . '/deploy/skyguardian-worker-notifications.timer');
$workflow = (string) file_get_contents($root . '/.github/workflows/deploy-worker-notifications.yml');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($api, "admin_authenticated"), 'settings API must require admin authentication');
$assert(str_contains($api, "hash_equals"), 'settings API must validate CSRF');
$assert(str_contains($api, "'configured'"), 'settings API must expose configured state');
$assert(!str_contains($api, "'bot_token' => (string)"), 'GET response must not expose Bot Token');
$assert(str_contains($api, "operation === 'test'"), 'settings API must support test delivery');
$assert(str_contains($api, 'TelegramBotNotifier'), 'test delivery must use Telegram notifier');
$assert(str_contains($api, 'cooldown_seconds'), 'settings API must configure cooldown');
$assert(str_contains($api, 'chmod($path, 0600)'), 'settings file must be private');
$assert(str_contains($service, 'Type=oneshot'), 'notification watcher must use oneshot service');
$assert(str_contains($service, 'User=www-data'), 'notification watcher must run as www-data');
$assert(str_contains($service, 'ReadWritePaths=/var/www/SkyGuardianUa/storage'), 'service must restrict writable paths');
$assert(str_contains($timer, 'OnUnitActiveSec=60s'), 'notification watcher must run every minute');
$assert(str_contains($timer, 'Persistent=true'), 'timer must catch up after downtime');
$assert(str_contains($workflow, 'systemctl enable --now skyguardian-worker-notifications.timer'), 'deployment must enable notification timer');
$assert(str_contains($workflow, 'systemctl is-active --quiet skyguardian-worker-notifications.timer'), 'deployment must verify timer state');

echo "Worker notification API and timer tests passed\n";
