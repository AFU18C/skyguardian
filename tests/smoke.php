#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SkyGuardian\Application;
use SkyGuardian\Moderation\MessageInspector;
use SkyGuardian\Moderation\SpamGuard;

$failures = [];
$health = Application::health();
if (($health['ok'] ?? false) !== true) {
    $failures[] = 'Application health failed';
}

$inspector = new MessageInspector();
if (!$inspector->containsLink('visit https://example.com')) {
    $failures[] = 'Link detection failed';
}
if (!$inspector->containsForbiddenWord('это запрещенное слово', ['запрещенное'])) {
    $failures[] = 'Forbidden-word detection failed';
}

$guard = new SpamGuard(2, 60);
if ($guard->register('chat:1') || $guard->register('chat:1') || !$guard->register('chat:1')) {
    $failures[] = 'Spam guard threshold failed';
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Smoke tests passed\n");
