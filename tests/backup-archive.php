<?php
declare(strict_types=1);

$projectDir = dirname(__DIR__);
$indexFile = $projectDir . '/public/index.php';

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_file($indexFile)) {
    $fail('public/index.php is missing');
}

$index = (string) file_get_contents($indexFile);
foreach ([
    "' --exclude=' . escapeshellarg('./storage/backups')",
    "' --exclude=' . escapeshellarg('./.git')",
    'escapeshellarg($backupPath)',
    'escapeshellarg($projectRoot)',
] as $needle) {
    if (!str_contains($index, $needle)) {
        $fail('production backup command is missing required protection: ' . $needle);
    }
}

$fixture = sys_get_temp_dir() . '/skyguardian-backup-' . bin2hex(random_bytes(8));
$archive = $fixture . '/result.tar.gz';

try {
    foreach ([
        $fixture,
        $fixture . '/public',
        $fixture . '/storage/backups',
        $fixture . '/storage/runtime',
        $fixture . '/.git/objects',
    ] as $directory) {
        if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
            $fail('could not create backup fixture directory');
        }
    }

    file_put_contents($fixture . '/public/index.php', '<?php echo "ok";');
    file_put_contents($fixture . '/storage/runtime/state.json', '{"ok":true}');
    file_put_contents($fixture . '/storage/backups/old.tar.gz', 'old backup');
    file_put_contents($fixture . '/.git/objects/private-object', 'git data');

    $command = 'tar -czf ' . escapeshellarg($archive)
        . ' --exclude=' . escapeshellarg('./storage/backups')
        . ' --exclude=' . escapeshellarg('./.git')
        . ' -C ' . escapeshellarg($fixture) . ' . 2>&1';

    exec($command, $output, $status);
    if ($status !== 0 || !is_file($archive) || filesize($archive) === 0) {
        $fail('backup fixture archive was not created');
    }

    exec('tar -tzf ' . escapeshellarg($archive) . ' 2>&1', $entries, $listStatus);
    if ($listStatus !== 0) {
        $fail('backup archive cannot be listed');
    }

    $normalized = array_map(
        static fn (string $entry): string => preg_replace('#^\./#', '', trim($entry)) ?? trim($entry),
        $entries
    );

    foreach (['public/index.php', 'storage/runtime/state.json'] as $required) {
        if (!in_array($required, $normalized, true)) {
            $fail('backup archive is missing required file: ' . $required);
        }
    }

    foreach ($normalized as $entry) {
        if ($entry === '.git' || str_starts_with($entry, '.git/')) {
            $fail('backup archive contains Git metadata');
        }
        if ($entry === 'storage/backups' || str_starts_with($entry, 'storage/backups/')) {
            $fail('backup archive recursively contains previous backups');
        }
    }

    fwrite(STDOUT, "Backup archive integrity test passed.\n");
} finally {
    if (is_dir($fixture)) {
        exec('rm -rf -- ' . escapeshellarg($fixture));
    }
}
