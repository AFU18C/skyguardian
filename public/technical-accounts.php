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
    $reply(401, ['ok' => false, 'message' => 'Требуется авторизация администратора.']);
}

$scope = trim((string) ($_REQUEST['scope'] ?? 'settings'));
if (!preg_match('/^[a-z][a-z0-9_-]{1,40}$/', $scope)) {
    $reply(422, ['ok' => false, 'message' => 'Некорректный раздел технических аккаунтов.']);
}

$storageDir = dirname(__DIR__) . '/storage/technical-accounts';
if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
    $reply(503, ['ok' => false, 'message' => 'Не удалось подготовить хранилище аккаунтов.']);
}
$file = $storageDir . '/' . $scope . '.json';

$readItems = static function () use ($file): array {
    if (!is_file($file)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $reply(200, ['ok' => true, 'items' => $readItems()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    $reply(405, ['ok' => false, 'message' => 'Разрешены только GET и POST.']);
}

if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
    $reply(419, ['ok' => false, 'message' => 'Сессия устарела. Обновите страницу.']);
}

$item = json_decode((string) ($_POST['item'] ?? ''), true);
if (!is_array($item) || !preg_match('/^[A-Za-z0-9_-]{8,80}$/', (string) ($item['id'] ?? ''))) {
    $reply(422, ['ok' => false, 'message' => 'Некорректные данные технического аккаунта.']);
}

$allowed = ['id','name','api_id','api_hash','connected','enabled','telegram_id','telegram_name','telegram_username','phone','connected_at'];
$clean = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $item)) {
        $clean[$key] = in_array($key, ['connected', 'enabled'], true)
            ? (bool) $item[$key]
            : mb_substr((string) $item[$key], 0, 500);
    }
}

$items = $readItems();
$index = null;
foreach ($items as $i => $existing) {
    if (($existing['id'] ?? null) === $clean['id']) {
        $index = $i;
        break;
    }
}
if ($index === null) {
    $items[] = $clean;
} else {
    $items[$index] = array_merge($items[$index], $clean);
}

$tmp = $file . '.tmp-' . bin2hex(random_bytes(6));
$json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $file)) {
    @unlink($tmp);
    $reply(503, ['ok' => false, 'message' => 'Не удалось сохранить технический аккаунт.']);
}
@chmod($file, 0660);

$reply(200, ['ok' => true, 'items' => $items]);
