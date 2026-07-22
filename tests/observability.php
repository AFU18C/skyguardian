<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SkyGuardian\Http\RequestContext;
use SkyGuardian\Logging\ErrorLogger;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$root = sys_get_temp_dir() . '/skyguardian-observability-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);

try {
    RequestContext::reset();
    $accepted = RequestContext::initialize('test-request-1234');
    $assert($accepted === 'test-request-1234', 'Valid incoming request ID must be preserved.');

    RequestContext::reset();
    $generated = RequestContext::initialize('bad id');
    $assert((bool) preg_match('/^[a-f0-9]{32}$/', $generated), 'Invalid request ID must be replaced.');

    $logger = new ErrorLogger($root);
    $logger->log(new RuntimeException('test failure'), [
        'token' => 'super-secret-token',
        'nested' => [
            'api_hash' => 'telegram-secret',
            'password' => 'password-secret',
            'safe' => 'visible',
        ],
    ]);

    $files = glob($root . '/logs/app-*.log') ?: [];
    $assert(count($files) === 1, 'Structured log file must be created.');
    $content = (string) file_get_contents($files[0]);
    $record = json_decode(trim($content), true, 512, JSON_THROW_ON_ERROR);

    $assert(($record['request_id'] ?? '') === $generated, 'Log must contain request ID.');
    $assert(($record['context']['token'] ?? null) === '[redacted]', 'Token must be redacted.');
    $assert(($record['context']['nested']['api_hash'] ?? null) === '[redacted]', 'API hash must be redacted.');
    $assert(($record['context']['nested']['password'] ?? null) === '[redacted]', 'Password must be redacted.');
    $assert(($record['context']['nested']['safe'] ?? null) === 'visible', 'Non-sensitive values must remain available.');
    $assert(!str_contains($content, 'super-secret-token'), 'Raw token must not appear in logs.');
    $assert(!str_contains($content, 'telegram-secret'), 'Raw API hash must not appear in logs.');

    echo "Observability tests passed.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    @rmdir($root);
}
