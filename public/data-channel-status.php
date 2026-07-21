<?php
declare(strict_types=1);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('skyguardian_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$reply = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SESSION['admin_authenticated'] ?? false) !== true) {
    $reply(401, ['ok' => false, 'message' => 'Требуется авторизация.']);
}

$scopeInput = trim((string) ($_GET['scope'] ?? ''));
$scope = match ($scopeInput) {
    'news', 'news-sources' => 'news',
    'alerts', 'alerts-sources' => 'alerts',
    default => '',
};
if ($scope === '') {
    $reply(422, ['ok' => false, 'message' => 'Не указан раздел каналов данных.']);
}

$storageDir = dirname(__DIR__) . '/storage';
$stateFile = $storageDir . '/telegram-' . $scope . '-channel-state.json';
$channelsFile = $storageDir . '/telegram-' . $scope . '-channels.json';

$readJson = static function (string $file): array {
    if (!is_file($file)) return [];
    $handle = fopen($file, 'rb');
    if ($handle === false) return [];
    try {
        flock($handle, LOCK_SH);
        $raw = stream_get_contents($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
};

$states = $readJson($stateFile);
$channels = array_values($readJson($channelsFile));
$result = [];

foreach ($channels as $channel) {
    if (!is_array($channel)) continue;
    $id = (string) ($channel['id'] ?? '');
    if ($id === '') continue;
    $state = is_array($states[$id] ?? null) ? $states[$id] : [];
    $result[$id] = [
        'status' => (string) ($state['status'] ?? (($channel['enabled'] ?? true) ? 'waiting' : 'paused')),
        'last_check_at' => $state['last_check_at'] ?? null,
        'last_publish_at' => $state['last_publish_at'] ?? null,
        'worker_seen_at' => $state['worker_seen_at'] ?? null,
        'last_message_id' => (int) ($state['last_message_id'] ?? 0),
        'published_count' => (int) ($state['published_count'] ?? 0),
        'last_error' => isset($state['last_error']) ? mb_substr((string) $state['last_error'], 0, 500) : null,
        'initialized' => (bool) ($state['initialized'] ?? false),
    ];
}

$reply(200, ['ok' => true, 'scope' => $scope, 'states' => $result]);
