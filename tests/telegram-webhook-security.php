<?php
declare(strict_types=1);

$projectDir = dirname(__DIR__);
$storageDir = $projectDir . '/storage';
$configFile = $storageDir . '/telegram-automation.json';
$serverLog = tempnam(sys_get_temp_dir(), 'skyguardian-webhook-');
$port = random_int(20000, 21999);
$baseUrl = 'http://127.0.0.1:' . $port;
$originalConfig = is_file($configFile) ? file_get_contents($configFile) : null;
$process = null;

$fail = static function (string $message) use (&$process, $serverLog): never {
    if (is_resource($process)) {
        proc_terminate($process);
    }
    fwrite(STDERR, "FAIL: {$message}\n");
    if (is_file($serverLog)) {
        $log = trim((string) file_get_contents($serverLog));
        if ($log !== '') {
            fwrite(STDERR, "Server log:\n{$log}\n");
        }
    }
    exit(1);
};

$request = static function (string $method, string $url, string $body = '', array $headers = []): array {
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);

    if ($response === false || $error !== '') {
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    return [
        'status' => $status,
        'headers' => substr((string) $response, 0, $headerSize),
        'body' => substr((string) $response, $headerSize),
    ];
};

try {
    if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
        $fail('could not create storage directory');
    }

    $secret = bin2hex(random_bytes(24));
    $config = [
        hash('sha256', 'test-token:test-chat') => [
            'id' => hash('sha256', 'test-token:test-chat'),
            'secret' => $secret,
            'bot_token' => 'test-token',
            'chat_id' => '-1001234567890',
            'enabled' => false,
            'group_enabled' => true,
            'mode' => 'webhook',
        ],
    ];
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    @chmod($configFile, 0600);

    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $projectDir . '/public'],
        [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
        $pipes,
        $projectDir
    );
    if (!is_resource($process)) {
        $fail('could not start PHP development server');
    }
    fclose($pipes[0]);

    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        usleep(100000);
        try {
            $probe = $request('GET', $baseUrl . '/telegram-webhook.php');
            if ($probe['status'] === 405) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
        }
    }
    if (!$ready) {
        $fail('webhook server did not become ready');
    }

    $get = $request('GET', $baseUrl . '/telegram-webhook.php');
    if ($get['status'] !== 405 || !preg_match('/^Allow:\s*POST\s*$/mi', $get['headers'])) {
        $fail('webhook does not reject non-POST requests correctly');
    }

    $missingSecret = $request('POST', $baseUrl . '/telegram-webhook.php', '{}', ['Content-Type: application/json']);
    if ($missingSecret['status'] !== 403) {
        $fail('webhook accepts a request without Telegram secret header');
    }

    $wrongSecret = $request('POST', $baseUrl . '/telegram-webhook.php?key=' . rawurlencode($secret), '{}', [
        'Content-Type: application/json',
        'X-Telegram-Bot-Api-Secret-Token: wrong-secret',
    ]);
    if ($wrongSecret['status'] !== 403) {
        $fail('webhook accepts a mismatched Telegram secret header');
    }

    $badJson = $request('POST', $baseUrl . '/telegram-webhook.php', '{', [
        'Content-Type: application/json',
        'X-Telegram-Bot-Api-Secret-Token: ' . $secret,
    ]);
    if ($badJson['status'] !== 400) {
        $fail('webhook does not reject invalid JSON');
    }

    $valid = $request('POST', $baseUrl . '/telegram-webhook.php', '{}', [
        'Content-Type: application/json',
        'X-Telegram-Bot-Api-Secret-Token: ' . $secret,
    ]);
    if ($valid['status'] !== 200 || trim($valid['body']) !== '{"ok":true}') {
        $fail('webhook does not accept a valid authenticated update');
    }

    foreach ([$get, $missingSecret, $wrongSecret, $badJson, $valid] as $response) {
        if (!preg_match('/^Cache-Control:\s*no-store\s*$/mi', $response['headers'])) {
            $fail('webhook response is missing no-store cache protection');
        }
        if (!preg_match('/^X-Content-Type-Options:\s*nosniff\s*$/mi', $response['headers'])) {
            $fail('webhook response is missing nosniff protection');
        }
    }

    fwrite(STDOUT, "Telegram webhook security smoke test passed.\n");
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($originalConfig === null) {
        @unlink($configFile);
    } else {
        file_put_contents($configFile, $originalConfig, LOCK_EX);
    }
    @unlink($serverLog);
}
