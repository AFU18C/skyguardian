#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SkyGuardian\Auth\AdminRepository;
use SkyGuardian\Operations\ReadinessService;
use SkyGuardian\Storage\JsonStore;

$failures = [];
$temp = sys_get_temp_dir() . '/skyguardian-readiness-' . bin2hex(random_bytes(5));
mkdir($temp, 0700, true);
$store = new JsonStore($temp);

try {
    $before = (new ReadinessService($temp, $store))->inspect();
    if (($before['ready'] ?? true) !== false) {
        $failures[] = 'Readiness passed without an administrator.';
    }
    if (($before['checks']['storage']['ok'] ?? false) !== true) {
        $failures[] = 'Writable storage was not detected.';
    }
    if (($before['checks']['admin']['ok'] ?? true) !== false) {
        $failures[] = 'Missing administrator was not detected.';
    }

    (new AdminRepository($store))->save('admin@example.com', password_hash('StrongPassword123!', PASSWORD_DEFAULT));

    $after = (new ReadinessService($temp, $store))->inspect();
    if (($after['ready'] ?? false) !== true) {
        $failures[] = 'Readiness failed after required configuration was added.';
    }
    if (($after['checks']['telegram_bot']['required'] ?? true) !== false) {
        $failures[] = 'Optional Telegram bot check was treated as required.';
    }
    if (array_key_exists('token', $after['checks']['telegram_bot'] ?? [])) {
        $failures[] = 'Readiness response exposed a Telegram token.';
    }
} catch (Throwable $e) {
    $failures[] = $e::class . ': ' . $e->getMessage();
} finally {
    foreach (glob($temp . '/*') ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
    @rmdir($temp);
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", array_unique($failures)) . "\n");
    exit(1);
}

fwrite(STDOUT, "Readiness tests passed\n");
