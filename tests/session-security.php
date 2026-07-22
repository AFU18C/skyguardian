<?php
declare(strict_types=1);

$projectDir = dirname(__DIR__);
$storageDir = $projectDir . '/storage';
$adminFile = $storageDir . '/admin.json';
$cookieJar = tempnam(sys_get_temp_dir(), 'skyguardian-session-cookie-');
$serverLog = tempnam(sys_get_temp_dir(), 'skyguardian-session-server-');
$port = random_int(22000, 23999);
$baseUrl = 'http://127.0.0.1:' . $port;
$originalAdmin = is_file($adminFile) ? file_get_contents($adminFile) : null;
$process = null;

$fail = static function (string $message) use (&$process, $serverLog): never {
    $details = is_file($serverLog) ? trim((string) file_get_contents($serverLog)) : '';
    if (is_resource($process)) proc_terminate($process);
    fwrite(STDERR, "FAIL: {$message}\n");
    if ($details !== '') fwrite(STDERR, "Server log:\n{$details}\n");
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
    if ($response === false || $error !== '') throw new RuntimeException('HTTP request failed: ' . $error);
    return [
        'status' => $status,
        'headers' => substr((string) $response, 0, $headerSize),
        'body' => substr((string) $response, $headerSize),
    ];
};

$sessionId = static function (string $cookieFile): ?string {
    if (!is_file($cookieFile)) return null;
    foreach (file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line[0] === '#') continue;
        $parts = explode("\t", $line);
        if (count($parts) >= 7 && $parts[5] === 'skyguardian_admin') return $parts[6];
    }
    return null;
};

try {
    if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) $fail('could not create storage directory');
    $password = 'Session-' . bin2hex(random_bytes(12));
    file_put_contents($adminFile, json_encode([
        'email' => 'session-audit@example.test',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'updated_at' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    @chmod($adminFile, 0600);

    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $projectDir . '/public'], [
        0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a'],
    ], $pipes, $projectDir);
    if (!is_resource($process)) $fail('could not start PHP development server');
    fclose($pipes[0]);

    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        usleep(100000);
        try {
            if ($request('GET', $baseUrl . '/?page=login', [], $cookieJar)['status'] === 200) { $ready = true; break; }
        } catch (Throwable) {}
    }
    if (!$ready) $fail('PHP development server did not become ready');

    $login = $request('GET', $baseUrl . '/?page=login', [], $cookieJar);
    if (!preg_match('/name="_token" value="([a-f0-9]{64})"/', $login['body'], $match)) $fail('login CSRF token missing');
    if (!preg_match('/Set-Cookie:\s*skyguardian_admin=.*HttpOnly.*SameSite=Strict/im', $login['headers'])) {
        $fail('session cookie is missing HttpOnly or SameSite=Strict');
    }
    $anonymousSessionId = $sessionId($cookieJar);
    if ($anonymousSessionId === null || $anonymousSessionId === '') $fail('anonymous session cookie missing');

    $authenticated = $request('POST', $baseUrl . '/?page=login', [
        '_token' => $match[1],
        'email' => 'session-audit@example.test',
        'password' => $password,
    ], $cookieJar);
    if ($authenticated['status'] !== 302) $fail('valid login did not redirect');

    $authenticatedSessionId = $sessionId($cookieJar);
    if ($authenticatedSessionId === null || $authenticatedSessionId === '') $fail('authenticated session cookie missing');
    if (hash_equals($anonymousSessionId, $authenticatedSessionId)) $fail('session id was not regenerated after login');

    $dashboard = $request('GET', $baseUrl . '/?page=home', [], $cookieJar);
    if ($dashboard['status'] !== 200) $fail('authenticated session cannot access dashboard');

    $logout = $request('GET', $baseUrl . '/?action=logout', [], $cookieJar);
    if ($logout['status'] !== 302) $fail('logout did not redirect');

    $afterLogout = $request('GET', $baseUrl . '/?page=home', [], $cookieJar);
    if ($afterLogout['status'] !== 302 || !preg_match('/^Location:\s*\/\?page=login\s*$/mi', $afterLogout['headers'])) {
        $fail('logged-out session can still access dashboard');
    }

    fwrite(STDOUT, "Session security smoke test passed.\n");
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if ($originalAdmin === null) @unlink($adminFile); else file_put_contents($adminFile, $originalAdmin, LOCK_EX);
    @unlink($cookieJar);
    @unlink($serverLog);
}
