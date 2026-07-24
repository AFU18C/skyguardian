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
if (($_SESSION['admin_authenticated'] ?? false) !== true) {
    $reply(401, ['ok'=>false,'message'=>'Требуется авторизация администратора.']);
}

$scopeRaw = strtolower(trim((string) ($_GET['scope'] ?? $_POST['scope'] ?? '')));
$scope = str_contains($scopeRaw, 'alert') ? 'alerts' : (str_contains($scopeRaw, 'news') ? 'news' : '');
if ($scope === '') $reply(422, ['ok'=>false,'message'=>'Неизвестный раздел каналов данных.']);

$storageDir = dirname(__DIR__) . '/storage';
$file = $storageDir . '/telegram-' . $scope . '-channels.json';
$lockFile = $storageDir . '/telegram-' . $scope . '-channels.lock';
$accountsFile = $storageDir . '/technical-accounts/telegram.json';

$readJsonArray = static function (string $path): array {
    if (!is_file($path)) return [];
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
};

$normalize = static function (array $items, array $accounts) use ($reply): array {
    if (count($items) > 10) $reply(422, ['ok'=>false,'message'=>'В одном разделе можно сохранить не более 10 каналов.']);

    $accountMap = [];
    foreach ($accounts as $account) {
        $id = trim((string) ($account['id'] ?? ''));
        if ($id !== '') $accountMap[$id] = $account;
    }

    $formats = ['original','text','text_without_links','media','text_and_media'];
    $starts = ['new','last_5','last_10','last_20'];
    $units = ['seconds','hours'];
    $result = [];

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{8,80}$/', $id)) {
            $reply(422, ['ok'=>false,'message'=>'Некорректный идентификатор канала.']);
        }
        if (isset($result[$id])) $reply(422, ['ok'=>false,'message'=>'Обнаружен дублирующийся канал.']);

        $name = trim((string) ($item['name'] ?? ''));
        $source = trim((string) ($item['source'] ?? ''));
        $destination = trim((string) ($item['destination'] ?? ''));
        $accountId = trim((string) ($item['account'] ?? ''));
        if ($name === '' || $source === '' || $destination === '' || $accountId === '') {
            $reply(422, ['ok'=>false,'message'=>'Заполните название, источник, получателя и технический аккаунт.']);
        }
        $account = $accountMap[$accountId] ?? null;
        if (!is_array($account) || empty($account['connected']) || empty($account['enabled'])) {
            $reply(422, ['ok'=>false,'message'=>'Выбранный технический аккаунт не подключён или выключен.']);
        }

        $format = (string) ($item['publication_format'] ?? '');
        $start = (string) ($item['processing_start'] ?? '');
        $unit = (string) ($item['check_frequency_unit'] ?? '');
        if (!in_array($format, $formats, true) || !in_array($start, $starts, true) || !in_array($unit, $units, true)) {
            $reply(422, ['ok'=>false,'message'=>'Некорректные параметры обработки канала.']);
        }
        $frequency = filter_var($item['check_frequency'] ?? null, FILTER_VALIDATE_INT);
        $min = $unit === 'hours' ? 1 : 3;
        $max = $unit === 'hours' ? 24 : 86400;
        if ($frequency === false || $frequency < $min || $frequency > $max) {
            $reply(422, ['ok'=>false,'message'=>'Некорректная частота проверки канала.']);
        }

        $result[$id] = [
            'id' => $id,
            'name' => mb_substr($name, 0, 200),
            'source' => mb_substr($source, 0, 500),
            'account' => $accountId,
            'destination' => mb_substr($destination, 0, 500),
            'publication_format' => $format,
            'check_frequency' => $frequency,
            'check_frequency_unit' => $unit,
            'processing_start' => $start,
            'keywords' => mb_substr(trim((string) ($item['keywords'] ?? '')), 0, 4000),
            'stop_words' => mb_substr(trim((string) ($item['stop_words'] ?? '')), 0, 4000),
            'custom_text_enabled' => (bool) ($item['custom_text_enabled'] ?? false),
            'custom_text_position' => (($item['custom_text_position'] ?? 'after') === 'before') ? 'before' : 'after',
            'custom_text' => mb_substr(trim((string) ($item['custom_text'] ?? '')), 0, 4000),
        ];
    }
    return array_values($result);
};

$write = static function (array $items) use ($file, $storageDir): void {
    if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Cannot create storage');
    }
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(6));
    $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write channels');
    }
    @chmod($file, 0660);
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $reply(200, ['ok'=>true,'scope'=>$scope,'items'=>$readJsonArray($file)]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $reply(405, ['ok'=>false,'message'=>'Разрешены только GET и POST.']);
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
        $reply(419, ['ok'=>false,'message'=>'Сессия устарела. Обновите страницу.']);
    }

    if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Cannot create storage');
    }
    $lock = fopen($lockFile, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock channels');
    try {
        $decoded = json_decode((string) ($_POST['items'] ?? '[]'), true);
        if (!is_array($decoded)) $reply(422, ['ok'=>false,'message'=>'Некорректные данные каналов.']);
        $items = $normalize($decoded, $readJsonArray($accountsFile));
        $write($items);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $reply(200, ['ok'=>true,'scope'=>$scope,'items'=>$items]);
} catch (Throwable $e) {
    error_log('Data channels error: ' . $e::class . ': ' . $e->getMessage());
    $reply(503, ['ok'=>false,'message'=>'Не удалось сохранить каналы данных.']);
}
