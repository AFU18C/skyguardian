<?php
declare(strict_types=1);

$projectDir = dirname(__DIR__);
$publicDir = $projectDir . '/public';
$serverLog = tempnam(sys_get_temp_dir(), 'skyguardian-public-surface-');
$port = random_int(22000, 23999);
$baseUrl = 'http://127.0.0.1:' . $port;
$process = null;

$fail = static function (string $message) use (&$process, $serverLog): never {
    $details = is_file($serverLog) ? trim((string) file_get_contents($serverLog)) : '';
    if (is_resource($process)) proc_terminate($process);
    fwrite(STDERR, "FAIL: {$message}\n");
    if ($details !== '') fwrite(STDERR, "Server log:\n{$details}\n");
    exit(1);
};

$request = static function (string $url): array {
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    if ($response === false || $error !== '') throw new RuntimeException('HTTP request failed: ' . $error);
    return [
        'status' => $status,
        'headers' => substr((string) $response, 0, $headerSize),
        'body' => substr((string) $response, $headerSize),
    ];
};

try {
    if (!is_dir($publicDir)) $fail('public document root is missing');

    foreach (['.env', '.git', 'storage', 'vendor', 'composer.json', 'composer.lock'] as $name) {
        if (file_exists($publicDir . '/' . $name)) $fail('sensitive project resource exists inside public/: ' . $name);
    }

    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $publicDir], [
        0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a'],
    ], $pipes, $projectDir);
    if (!is_resource($process)) $fail('could not start PHP development server');
    fclose($pipes[0]);

    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        usleep(100000);
        try {
            if ($request($baseUrl . '/?page=login')['status'] === 200) { $ready = true; break; }
        } catch (Throwable) {}
    }
    if (!$ready) $fail('PHP development server did not become ready');

    $sensitiveUrls = [
        '/.env',
        '/.git/config',
        '/composer.json',
        '/composer.lock',
        '/storage/admin.json',
        '/storage/skyguardian.json',
        '/vendor/autoload.php',
        '/bin/verify-production.php',
        '/src/Worker/WorkerStatusService.php',
        '/..%2Fcomposer.json',
        '/%2e%2e/composer.json',
    ];

    foreach ($sensitiveUrls as $path) {
        $response = $request($baseUrl . $path);
        if ($response['status'] === 200) $fail('sensitive URL is publicly readable: ' . $path);

        // PHP's development server includes the requested path in its default 404
        // response. Do not treat a filename such as "autoload.php" as leaked file
        // contents; rely on the non-200 status and scan only for actual secrets.
        $body = strtolower($response['body']);
        foreach (['password_hash', 'bot_token', 'api_hash', 'skyguardian\\worker'] as $secretMarker) {
            if (str_contains($body, strtolower($secretMarker))) $fail('sensitive content leaked through ' . $path);
        }
    }

    fwrite(STDOUT, "Public surface exposure security test passed.\n");
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    @unlink($serverLog);
}
