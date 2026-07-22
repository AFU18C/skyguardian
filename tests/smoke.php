#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SkyGuardian\Application;
use SkyGuardian\Auth\PasswordPolicy;
use SkyGuardian\Moderation\MessageInspector;
use SkyGuardian\Moderation\SpamGuard;
use SkyGuardian\Storage\JsonStore;

$failures = [];
$temp = sys_get_temp_dir() . '/skyguardian-test-' . bin2hex(random_bytes(5));
mkdir($temp, 0700, true);
$store = new JsonStore($temp);

try {
    if (((new Application())->health()['ok'] ?? false) !== true) {
        $failures[] = 'Application health failed';
    }

    $inspector = new MessageInspector();
    if (!$inspector->containsLink('visit https://example.com')) {
        $failures[] = 'Link detection failed';
    }
    if (!$inspector->containsForbiddenWord('это запрещенное слово', ['запрещенное'])) {
        $failures[] = 'Forbidden-word detection failed';
    }

    $guard = new SpamGuard($store);
    for ($i = 0; $i < 5; $i++) {
        if ($guard->isSpam('chat', 1, 5, 60)) {
            $failures[] = 'Spam triggered too early';
        }
    }
    if (!$guard->isSpam('chat', 1, 5, 60)) {
        $failures[] = 'Spam threshold failed';
    }

    $store->write('sample', ['a' => 1]);
    $store->update('sample', static function (array $data): array {
        $data['b'] = 2;
        return $data;
    });
    if ($store->read('sample') !== ['a' => 1, 'b' => 2]) {
        $failures[] = 'Atomic update failed';
    }

    try {
        (new PasswordPolicy())->validate('short');
        $failures[] = 'Weak password accepted';
    } catch (InvalidArgumentException) {
    }
} catch (Throwable $e) {
    $failures[] = $e::class . ': ' . $e->getMessage();
} finally {
    foreach (glob($temp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($temp);
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", array_unique($failures)) . "\n");
    exit(1);
}

fwrite(STDOUT, "Smoke tests passed\n");
