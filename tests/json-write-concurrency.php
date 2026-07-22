<?php
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/skyguardian-json-' . bin2hex(random_bytes(6));
$file = $dir . '/state.json';
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "FAIL: could not create temp directory\n");
    exit(1);
}

$writer = <<<'PHP'
<?php
[$script, $file, $writerId] = $argv;
$dir = dirname($file);
for ($i = 0; $i < 80; $i++) {
    $payload = [
        'writer' => (int) $writerId,
        'iteration' => $i,
        'nonce' => bin2hex(random_bytes(32)),
        'items' => array_fill(0, 50, ['ok' => true, 'value' => str_repeat((string) $writerId, 20)]),
    ];
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $temp = tempnam($dir, '.state-');
    if ($temp === false) exit(10);
    if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false) exit(11);
    chmod($temp, 0600);
    if (!rename($temp, $file)) exit(12);
}
PHP;

$writerFile = $dir . '/writer.php';
file_put_contents($writerFile, $writer, LOCK_EX);

$processes = [];
for ($id = 1; $id <= 12; $id++) {
    $process = proc_open([PHP_BINARY, $writerFile, $file, (string) $id], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $dir);
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL: could not start writer {$id}\n");
        exit(1);
    }
    fclose($pipes[0]);
    $processes[] = [$process, $pipes, $id];
}

foreach ($processes as [$process, $pipes, $id]) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        fwrite(STDERR, "FAIL: writer {$id} exited {$code}: {$stdout} {$stderr}\n");
        exit(1);
    }
}

$raw = is_file($file) ? file_get_contents($file) : false;
if ($raw === false || $raw === '') {
    fwrite(STDERR, "FAIL: final JSON file missing or empty\n");
    exit(1);
}

try {
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL: final JSON is corrupted: {$exception->getMessage()}\n");
    exit(1);
}

if (!is_array($decoded) || !isset($decoded['writer'], $decoded['iteration'], $decoded['nonce'], $decoded['items'])) {
    fwrite(STDERR, "FAIL: final JSON structure is incomplete\n");
    exit(1);
}
if (count($decoded['items']) !== 50) {
    fwrite(STDERR, "FAIL: final JSON payload was partially written\n");
    exit(1);
}

$notificationEndpoint = (string) file_get_contents(dirname(__DIR__) . '/public/worker-notifications.php');
foreach (['tempnam(', 'LOCK_EX', 'rename(', 'chmod($temp, 0600)'] as $needle) {
    if (!str_contains($notificationEndpoint, $needle)) {
        fwrite(STDERR, "FAIL: worker notification writer missing atomic-write safeguard: {$needle}\n");
        exit(1);
    }
}

@unlink($file);
@unlink($writerFile);
@rmdir($dir);
fwrite(STDOUT, "Concurrent JSON write stress test passed.\n");
