#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectDir = dirname(__DIR__);
$storageDir = $projectDir . '/storage';
$errors = [];
$warnings = [];
$checks = 0;

$check = static function (bool $condition, string $error) use (&$errors, &$checks): void {
    $checks++;
    if (!$condition) {
        $errors[] = $error;
    }
};

$warn = static function (bool $condition, string $warning) use (&$warnings, &$checks): void {
    $checks++;
    if (!$condition) {
        $warnings[] = $warning;
    }
};

$requiredFiles = [
    'vendor/autoload.php',
    'artisan',
    'public/index.php',
    'public/telegram-webhook.php',
    'public/worker-status.php',
    'public/worker-notifications.php',
    'public/TelegramAutomation.php',
    'bin/data-channel-worker.php',
    'bin/worker-notification-watch.php',
    'deploy/skyguardian-data-news.service',
    'deploy/skyguardian-data-alerts.service',
    'deploy/skyguardian-worker-notifications.service',
    'deploy/skyguardian-worker-notifications.timer',
];

foreach ($requiredFiles as $file) {
    $check(is_file($projectDir . '/' . $file), 'Missing required file: ' . $file);
}

foreach (['curl', 'json', 'mbstring', 'openssl', 'session'] as $extension) {
    $check(extension_loaded($extension), 'Required PHP extension is missing: ' . $extension);
}

$check(PHP_VERSION_ID >= 80300, 'PHP 8.3 or newer is required; current version is ' . PHP_VERSION);
$check(is_dir($storageDir), 'Storage directory is missing.');
$check(is_dir($storageDir) && is_writable($storageDir), 'Storage directory is not writable.');

$adminFile = $storageDir . '/admin.json';
$check(is_file($adminFile), 'Administrator is not configured. Run: php artisan admin:create');
if (is_file($adminFile)) {
    $admin = json_decode((string) file_get_contents($adminFile), true);
    $check(is_array($admin), 'storage/admin.json is invalid JSON.');
    if (is_array($admin)) {
        $email = trim((string) ($admin['email'] ?? ''));
        $hash = (string) ($admin['password_hash'] ?? '');
        $check(filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Administrator email is invalid.');
        $check($hash !== '' && password_get_info($hash)['algo'] !== null, 'Administrator password hash is invalid.');
        $check(!array_key_exists('password', $admin), 'Plain administrator password must not be stored.');
    }
}

$sensitiveFiles = [
    'admin.json',
    'telegram-accounts.json',
    'telegram-news-accounts.json',
    'telegram-news-channels.json',
    'telegram-alerts-channels.json',
    'worker-notifications.json',
];
foreach ($sensitiveFiles as $name) {
    $path = $storageDir . '/' . $name;
    if (!is_file($path)) {
        continue;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    $check(is_array($decoded), 'Invalid JSON in storage/' . $name);
    $permissions = fileperms($path);
    if ($permissions !== false) {
        $warn(($permissions & 0007) === 0, 'storage/' . $name . ' is accessible to other system users. Recommended mode: 0600 or 0640.');
    }
}

foreach (['news', 'alerts'] as $scope) {
    $metrics = $storageDir . '/data-channel-worker-' . $scope . '-metrics.json';
    if (!is_file($metrics)) {
        $warnings[] = $scope . ' worker has not produced metrics yet.';
        continue;
    }

    $data = json_decode((string) file_get_contents($metrics), true);
    $check(is_array($data), 'Invalid metrics JSON for ' . $scope . ' worker.');
    if (!is_array($data)) {
        continue;
    }

    $finishedAt = isset($data['finished_at']) ? strtotime((string) $data['finished_at']) : false;
    if ($finishedAt === false) {
        $warnings[] = $scope . ' worker metrics do not contain a valid finished_at timestamp.';
    } elseif (time() - $finishedAt > 600) {
        $warnings[] = $scope . ' worker metrics are older than 10 minutes.';
    }

    if (($data['status'] ?? null) === 'error') {
        $errors[] = $scope . ' worker reports error status.';
    }
}

$notificationConfig = $storageDir . '/worker-notifications.json';
if (is_file($notificationConfig)) {
    $config = json_decode((string) file_get_contents($notificationConfig), true);
    $check(is_array($config), 'Notification configuration JSON is invalid.');
    if (is_array($config) && ($config['enabled'] ?? false) === true) {
        $check(trim((string) ($config['bot_token'] ?? '')) !== '', 'Notifications are enabled without a bot token.');
        $check(trim((string) ($config['chat_id'] ?? '')) !== '', 'Notifications are enabled without a chat ID.');
    }
}

$phpFiles = [
    'public/index.php',
    'public/telegram-webhook.php',
    'public/worker-status.php',
    'public/worker-notifications.php',
    'public/TelegramAutomation.php',
    'bin/data-channel-worker.php',
    'bin/worker-notification-watch.php',
];
foreach ($phpFiles as $file) {
    $path = $projectDir . '/' . $file;
    if (!is_file($path)) {
        continue;
    }
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    $check($code === 0, 'PHP syntax check failed for ' . $file . ': ' . implode(' ', $output));
}

foreach ($warnings as $warning) {
    fwrite(STDOUT, "WARNING: {$warning}\n");
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
    fwrite(STDERR, sprintf("SkyGuardian production verification failed: %d error(s), %d warning(s).\n", count($errors), count($warnings)));
    exit(1);
}

fwrite(STDOUT, sprintf("SkyGuardian production verification passed (%d checks, %d warning(s)).\n", $checks, count($warnings)));
exit(0);
