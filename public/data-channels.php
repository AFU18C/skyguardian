<?php
declare(strict_types=1);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('skyguardian_admin');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$reply = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
if (($_SESSION['admin_authenticated'] ?? false) !== true) $reply(401, ['ok'=>false,'message'=>'Требуется авторизация администратора.']);

$scopeRaw = strtolower(trim((string) ($_GET['scope'] ?? $_POST['scope'] ?? '')));
$scope = str_contains($scopeRaw, 'alert') ? 'alerts' : (str_contains($scopeRaw, 'news') ? 'news' : '');
if ($scope === '') $reply(422, ['ok'=>false,'message'=>'Неизвестный раздел каналов данных.']);

$file = dirname(__DIR__) . '/storage/telegram-' . $scope . '-channels.json';
$read = static function () use ($file): array {
    if (!is_file($file)) return [];
    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
};
$normalize = static function (array $items): array {
    $result = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{8,80}$/', $id)) continue;
        $clean = ['id'=>$id];
        foreach (['name','source','account','destination','publication_format','check_frequency','check_frequency_unit','processing_start','keywords','stop_words','custom_text_position','custom_text'] as $key) {
            if (array_key_exists($key, $item)) $clean[$key] = mb_substr((string) $item[$key], 0, 4000);
        }
        $clean['custom_text_enabled'] = (bool) ($item['custom_text_enabled'] ?? false);
        $result[$id] = $clean;
    }
    return array_values($result);
};
$write = static function (array $items) use ($file): void {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('Cannot create storage');
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(6));
    $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write channels');
    }
    @chmod($file, 0660);
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') $reply(200, ['ok'=>true,'scope'=>$scope,'items'=>$read()]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $reply(405, ['ok'=>false,'message'=>'Разрешены только GET и POST.']);
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['_token'] ?? ''))) $reply(419, ['ok'=>false,'message'=>'Сессия устарела. Обновите страницу.']);
    $decoded = json_decode((string) ($_POST['items'] ?? '[]'), true);
    if (!is_array($decoded)) $reply(422, ['ok'=>false,'message'=>'Некорректные данные каналов.']);
    $items = $normalize($decoded);
    $write($items);
    $reply(200, ['ok'=>true,'scope'=>$scope,'items'=>$items]);
} catch (Throwable $e) {
    error_log('Data channels error: ' . $e::class . ': ' . $e->getMessage());
    $reply(503, ['ok'=>false,'message'=>'Не удалось сохранить каналы данных.']);
}
