<?php
declare(strict_types=1);

$projectDir = dirname(__DIR__);
$storageDir = $projectDir . '/storage';
$adminFile = $storageDir . '/admin.json';
$cookieJar = tempnam(sys_get_temp_dir(), 'skyguardian-cookie-');
$serverLog = tempnam(sys_get_temp_dir(), 'skyguardian-server-');
$port = random_int(18080, 19999);
$baseUrl = 'http://127.0.0.1:' . $port;
$originalAdmin = is_file($adminFile) ? file_get_contents($adminFile) : null;
$process = null;

$fail = static function (string $message) use (&$process, $serverLog): never {
    $details = is_file($serverLog) ? trim((string) file_get_contents($serverLog)) : '';
    if (is_resource($process)) {
        proc_terminate($process);
    }
    fwrite(STDERR, "FAIL: {$message}\n");
    if ($details !== '') {
        fwrite(STDERR, "Server log:\n{$details}\n");
    }
    exit(1);
};

$request = static function (string $method, string $url, array $fields = [], ?string $cookieFile = null): array {
    $handle = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 10,
    ];
    if ($fields !== []) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
    }
    if ($cookieFile !== null) {
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
    }
    curl_setopt_array($handle, $options);
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

    $password = 'Audit-' . bin2hex(random_bytes(12));
    $admin = [
        'email' => 'audit@example.test',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'updated_at' => gmdate(DATE_ATOM),
    ];
    file_put_contents($adminFile, json_encode($admin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    @chmod($adminFile, 0600);

    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $projectDir . '/public'];
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['file', $serverLog, 'a'],
        2 => ['file', $serverLog, 'a'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $projectDir);
    if (!is_resource($process)) {
        $fail('could not start PHP development server');
    }
    fclose($pipes[0]);

    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        usleep(100000);
        try {
            $probe = $request('GET', $baseUrl . '/?page=login', [], $cookieJar);
            if ($probe['status'] === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
            // Server may still be starting.
        }
    }
    if (!$ready) {
        $fail('PHP development server did not become ready');
    }

    $landing = $request('GET', $baseUrl . '/', [], $cookieJar);
    if ($landing['status'] !== 200 || !str_contains($landing['body'], 'Ведутся работы')) {
        $fail('public landing page is not available');
    }

    $login = $request('GET', $baseUrl . '/?page=login', [], $cookieJar);
    if ($login['status'] !== 200 || !preg_match('/name="_token" value="([a-f0-9]{64})"/', $login['body'], $match)) {
        $fail('login page does not provide a valid CSRF token');
    }
    $csrf = $match[1];

    $unauthorized = $request('POST', $baseUrl . '/?action=telegram-check', [
        '_token' => $csrf,
        'bot_token' => '123456:abcdefghijklmnopqrstuvwxyzABCDEFGH',
        'chat_id' => '-1001234567890',
    ]);
    if ($unauthorized['status'] !== 401) {
        $fail('protected Telegram endpoint does not reject an unauthenticated request');
    }

    $badLogin = $request('POST', $baseUrl . '/?page=login', [
        '_token' => $csrf,
        'email' => 'audit@example.test',
        'password' => 'wrong-password',
    ], $cookieJar);
    if ($badLogin['status'] !== 200 || !str_contains($badLogin['body'], 'Неверная почта или пароль')) {
        $fail('invalid credentials are not rejected safely');
    }

    $goodLogin = $request('POST', $baseUrl . '/?page=login', [
        '_token' => $csrf,
        'email' => 'audit@example.test',
        'password' => $password,
    ], $cookieJar);
    if ($goodLogin['status'] !== 302 || !preg_match('/^Location:\s*\/\?page=home\s*$/mi', $goodLogin['headers'])) {
        $fail('valid credentials do not redirect to the dashboard');
    }

    $dashboard = $request('GET', $baseUrl . '/?page=home', [], $cookieJar);
    if ($dashboard['status'] !== 200 || !str_contains($dashboard['body'], 'SkyGuardian')) {
        $fail('authenticated dashboard is not available');
    }
    if (!preg_match('/(?:name="_token" value|data-csrf)="([a-f0-9]{64})"/', $dashboard['body'], $dashboardTokenMatch)) {
        $fail('dashboard does not expose a valid CSRF token');
    }
    $dashboardCsrf = $dashboardTokenMatch[1];

    $missingCsrf = $request('POST', $baseUrl . '/?action=telegram-check', [
        'bot_token' => '123456:abcdefghijklmnopqrstuvwxyzABCDEFGH',
        'chat_id' => '-1001234567890',
    ], $cookieJar);
    if ($missingCsrf['status'] !== 419) {
        $fail('protected Telegram endpoint does not reject a missing CSRF token');
    }

    $invalidTelegram = $request('POST', $baseUrl . '/?action=telegram-check', [
        '_token' => $dashboardCsrf,
        'bot_token' => 'invalid-token',
        'chat_id' => '-1001234567890',
    ], $cookieJar);
    if ($invalidTelegram['status'] !== 422) {
        $fail('authenticated request with a valid CSRF token did not reach endpoint validation');
    }

    $logoutGet = $request('GET', $baseUrl . '/?action=logout', [], $cookieJar);
    if ($logoutGet['status'] !== 405) {
        $fail('GET logout is not rejected');
    }

    $logout = $request('POST', $baseUrl . '/?action=logout', [
        '_token' => $dashboardCsrf,
    ], $cookieJar);
    if ($logout['status'] !== 302 || !preg_match('/^Location:\s*\/\?page=login\s*$/mi', $logout['headers'])) {
        $fail('POST logout does not redirect to the login page');
    }

    $afterLogout = $request('GET', $baseUrl . '/?page=home', [], $cookieJar);
    if ($afterLogout['status'] !== 302 || !preg_match('/^Location:\s*\/\?page=login\s*$/mi', $afterLogout['headers'])) {
        $fail('session remains authenticated after logout');
    }

    fwrite(STDOUT, "HTTP authentication smoke test passed.\n");
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($originalAdmin === null) {
        @unlink($adminFile);
    } else {
        file_put_contents($adminFile, $originalAdmin, LOCK_EX);
    }
    @unlink($cookieJar);
    @unlink($serverLog);
}
