<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$projectDir = dirname(__DIR__);
$storageDir = $projectDir . '/storage';
$production = in_array('--production', $argv, true);
$errors = [];
$warnings = [];

$check = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$check(is_file($projectDir . '/composer.json'), 'composer.json is missing');
$check(is_file($projectDir . '/composer.lock'), 'composer.lock is missing');
$check(is_file($projectDir . '/vendor/autoload.php'), 'vendor/autoload.php is missing');
$check(is_dir($storageDir), 'storage directory is missing');
$check(is_dir($storageDir) && is_writable($storageDir), 'storage directory is not writable');

$adminFile = $storageDir . '/admin.json';
if (is_file($adminFile)) {
    $adminRaw = file_get_contents($adminFile);
    $admin = is_string($adminRaw) ? json_decode($adminRaw, true) : null;
    $check(is_array($admin), 'storage/admin.json is invalid JSON');
    if (is_array($admin)) {
        $check(filter_var((string)($admin['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false, 'administrator email is invalid');
        $hash = (string)($admin['password_hash'] ?? '');
        $check($hash !== '' && password_get_info($hash)['algo'] !== null, 'administrator password hash is invalid');
    }

    $permissions = fileperms($adminFile);
    if ($permissions !== false && (($permissions & 0007) !== 0)) {
        $errors[] = 'storage/admin.json is accessible to other OS users';
    }
} elseif ($production) {
    $errors[] = 'administrator is not configured; run php artisan admin:create';
} else {
    $warnings[] = 'administrator is not configured (allowed outside production audit)';
}

foreach ([
    'telegram-automation.json',
    'telegram-automation-state.json',
    'worker-notifications.json',
] as $sensitiveFile) {
    $path = $storageDir . '/' . $sensitiveFile;
    if (!is_file($path)) {
        continue;
    }
    $permissions = fileperms($path);
    if ($permissions !== false && (($permissions & 0007) !== 0)) {
        $errors[] = 'storage/' . $sensitiveFile . ' is accessible to other OS users';
    }
}

foreach (['public', 'src', 'bin'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $projectDir . '/' . $directory,
        FilesystemIterator::SKIP_DOTS
    ));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1';
        exec($command, $output, $status);
        if ($status !== 0) {
            $errors[] = 'PHP syntax error: ' . $file->getPathname();
        }
    }
}

if ($production && is_executable('/usr/bin/systemctl')) {
    foreach (['skyguardian-data-news.service', 'skyguardian-data-alerts.service'] as $service) {
        exec('/usr/bin/systemctl is-active --quiet ' . escapeshellarg($service), $output, $status);
        if ($status !== 0) {
            $errors[] = $service . ' is not active';
        }
    }
}

foreach ($warnings as $warning) {
    fwrite(STDOUT, "WARN: {$warning}\n");
}
foreach ($errors as $error) {
    fwrite(STDERR, "FAIL: {$error}\n");
}

if ($errors !== []) {
    fwrite(STDERR, 'Runtime audit failed with ' . count($errors) . " error(s).\n");
    exit(1);
}

fwrite(STDOUT, "Runtime audit passed.\n");
