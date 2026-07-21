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
header('Cache-Control: no-store');

$reply = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SESSION['admin_authenticated'] ?? false) !== true) {
    $reply(401, ['ok' => false, 'message' => 'Требуется авторизация.']);
}

$scopeInput = trim((string) ($_GET['scope'] ?? $_POST['scope'] ?? ''));
$scope = match ($scopeInput) {
    'news', 'news-sources' => 'news',
    'alerts', 'alerts-sources' => 'alerts',
    default => '',
};
if ($scope === '') {
    $reply(422, ['ok' => false, 'message' => 'Не указан раздел каналов данных.']);
}

$storageDir = dirname(__DIR__) . '/storage';
$channelsFile = $storageDir . '/telegram-' . $scope . '-channels.json';
$accountsFile = $scope === 'news'
    ? $storageDir . '/telegram-news-accounts.json'
    : $storageDir . '/telegram-accounts.json';

$ensureStorage = static function () use ($storageDir): void {
    if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Не удалось подготовить хранилище.');
    }
    if (!is_writable($storageDir)) {
        throw new RuntimeException('Хранилище недоступно для записи.');
    }
};

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
    return is_array($data) ? array_values($data) : [];
};

$writeJson = static function (string $file, array $data) use ($storageDir): void {
    $json = json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temp = tempnam($storageDir, '.data-channel-');
    if ($temp === false) throw new RuntimeException('Не удалось создать временный файл.');
    try {
        if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать настройки канала.');
        }
        chmod($temp, 0600);
        if (!rename($temp, $file)) {
            throw new RuntimeException('Не удалось применить настройки канала.');
        }
        chmod($file, 0600);
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
};

$publicAccount = static function (array $account): ?array {
    if (!(bool) ($account['api_verified'] ?? false)) return null;
    if (!(bool) ($account['connected'] ?? false)) return null;
    return [
        'id' => (string) ($account['id'] ?? ''),
        'name' => (string) ($account['name'] ?? ''),
        'enabled' => (bool) ($account['enabled'] ?? true),
        'connected' => true,
        'user_name' => trim((string) (($account['user']['name'] ?? '') ?: ($account['user']['username'] ?? ''))),
    ];
};

$normalizePeer = static function (string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('~^https?://t\.me/(?:s/)?([A-Za-z0-9_+\-]+)~i', $value, $matches)) {
        return str_starts_with($matches[1], '+') ? $value : '@' . $matches[1];
    }
    return $value;
};

$splitTerms = static function (string $value): array {
    $items = preg_split('/\s*,\s*/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $items = array_values(array_unique(array_filter(array_map(static fn ($item): string => mb_substr(trim((string) $item), 0, 120), $items))));
    return array_slice($items, 0, 100);
};

$publicChannel = static function (array $channel): array {
    unset($channel['last_error_internal']);
    return $channel;
};

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'bootstrap');

try {
    $ensureStorage();
    $channels = $readJson($channelsFile);
    $accounts = array_values(array_filter(array_map($publicAccount, $readJson($accountsFile))));

    if ($action === 'bootstrap' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $reply(200, [
            'ok' => true,
            'scope' => $scope,
            'csrf' => (string) ($_SESSION['csrf_token'] ?? ''),
            'channels' => array_map($publicChannel, $channels),
            'accounts' => $accounts,
            'limit' => 10,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $reply(405, ['ok' => false, 'message' => 'Метод не поддерживается.']);
    }

    $csrf = (string) ($_POST['_token'] ?? '');
    if ($csrf === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        $reply(419, ['ok' => false, 'message' => 'Сессия устарела. Обновите страницу.']);
    }

    if ($action === 'delete') {
        $id = trim((string) ($_POST['id'] ?? ''));
        $before = count($channels);
        $channels = array_values(array_filter($channels, static fn ($item): bool => (string) ($item['id'] ?? '') !== $id));
        if (count($channels) === $before) throw new InvalidArgumentException('Канал данных не найден.');
        $writeJson($channelsFile, $channels);
        $reply(200, ['ok' => true, 'message' => 'Канал данных удалён.']);
    }

    if ($action === 'toggle') {
        $id = trim((string) ($_POST['id'] ?? ''));
        $found = false;
        foreach ($channels as &$channel) {
            if ((string) ($channel['id'] ?? '') !== $id) continue;
            $enabled = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOL);
            $channel['enabled'] = $enabled;
            $channel['updated_at'] = gmdate(DATE_ATOM);
            if ($enabled) {
                $channel['enabled_since'] = gmdate(DATE_ATOM);
            }
            $found = true;
            $updated = $channel;
            break;
        }
        unset($channel);
        if (!$found) throw new InvalidArgumentException('Канал данных не найден.');
        $writeJson($channelsFile, $channels);
        $reply(200, ['ok' => true, 'message' => $updated['enabled'] ? 'Мониторинг включён.' : 'Мониторинг остановлен.', 'channel' => $publicChannel($updated)]);
    }

    if ($action !== 'save') throw new InvalidArgumentException('Неизвестная операция.');

    $id = trim((string) ($_POST['id'] ?? ''));
    $isNew = $id === '';
    if ($isNew) {
        if (count($channels) >= 10) throw new InvalidArgumentException('В одном разделе можно добавить не более 10 каналов данных.');
        $id = bin2hex(random_bytes(16));
    }
    if (!preg_match('/^[a-f0-9-]{16,64}$/i', $id)) throw new InvalidArgumentException('Некорректный идентификатор канала.');

    $name = trim((string) ($_POST['name'] ?? ''));
    $source = $normalizePeer((string) ($_POST['source'] ?? ''));
    $destination = $normalizePeer((string) ($_POST['destination'] ?? ''));
    $accountId = trim((string) ($_POST['account'] ?? ''));
    $format = trim((string) ($_POST['publication_format'] ?? ''));
    $frequency = (int) ($_POST['check_frequency'] ?? 0);
    $frequencyUnit = trim((string) ($_POST['check_frequency_unit'] ?? 'seconds'));
    $processingStart = trim((string) ($_POST['processing_start'] ?? 'new'));

    if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Введите название канала данных.');
    if ($source === '' || mb_strlen($source) > 255) throw new InvalidArgumentException('Укажите источник сообщений.');
    if ($destination === '' || mb_strlen($destination) > 255) throw new InvalidArgumentException('Укажите канал или группу для публикации.');
    if ($source === $destination) throw new InvalidArgumentException('Источник и назначение должны отличаться.');
    if (!in_array($format, ['original', 'text', 'text_without_links', 'media', 'text_and_media'], true)) throw new InvalidArgumentException('Выберите формат публикации.');
    if (!in_array($frequencyUnit, ['seconds', 'hours'], true)) throw new InvalidArgumentException('Некорректная единица частоты.');
    if ($frequencyUnit === 'seconds' && ($frequency < 3 || $frequency > 86400)) throw new InvalidArgumentException('Частота должна быть от 3 до 86400 секунд.');
    if ($frequencyUnit === 'hours' && ($frequency < 1 || $frequency > 24)) throw new InvalidArgumentException('Частота должна быть от 1 до 24 часов.');
    if (!in_array($processingStart, ['new', 'last_5', 'last_10', 'last_20'], true)) throw new InvalidArgumentException('Выберите начало обработки.');

    $account = null;
    foreach ($accounts as $candidate) {
        if ((string) ($candidate['id'] ?? '') === $accountId) {
            $account = $candidate;
            break;
        }
    }
    if ($account === null) throw new InvalidArgumentException('Выберите подключённый технический аккаунт этого раздела.');

    $existingIndex = -1;
    $existing = [];
    foreach ($channels as $index => $channel) {
        if ((string) ($channel['id'] ?? '') === $id) {
            $existingIndex = (int) $index;
            $existing = (array) $channel;
            break;
        }
    }

    $customTextEnabled = filter_var($_POST['custom_text_enabled'] ?? false, FILTER_VALIDATE_BOOL);
    $customText = trim((string) ($_POST['custom_text'] ?? ''));
    if ($customTextEnabled && $customText === '') throw new InvalidArgumentException('Введите собственный текст.');

    $channel = array_merge($existing, [
        'id' => $id,
        'scope' => $scope,
        'name' => $name,
        'source' => $source,
        'account' => $accountId,
        'destination' => $destination,
        'publication_format' => $format,
        'check_frequency' => $frequency,
        'check_frequency_unit' => $frequencyUnit,
        'processing_start' => $processingStart,
        'keywords' => $splitTerms((string) ($_POST['keywords'] ?? '')),
        'stop_words' => $splitTerms((string) ($_POST['stop_words'] ?? '')),
        'custom_text_enabled' => $customTextEnabled,
        'custom_text_position' => ((string) ($_POST['custom_text_position'] ?? 'after')) === 'before' ? 'before' : 'after',
        'custom_text' => mb_substr($customText, 0, 2000),
        'enabled' => (bool) ($existing['enabled'] ?? true),
        'enabled_since' => $existing['enabled_since'] ?? ($existing['created_at'] ?? gmdate(DATE_ATOM)),
        'status' => (string) ($existing['status'] ?? 'waiting'),
        'last_check_at' => $existing['last_check_at'] ?? null,
        'last_publish_at' => $existing['last_publish_at'] ?? null,
        'last_error' => $existing['last_error'] ?? null,
        'created_at' => $existing['created_at'] ?? gmdate(DATE_ATOM),
        'updated_at' => gmdate(DATE_ATOM),
    ]);

    if ($existingIndex >= 0) $channels[$existingIndex] = $channel; else $channels[] = $channel;
    $writeJson($channelsFile, $channels);
    $reply(200, ['ok' => true, 'message' => $existingIndex >= 0 ? 'Канал данных сохранён.' : 'Канал данных добавлен.', 'channel' => $publicChannel($channel)]);
} catch (InvalidArgumentException $exception) {
    $reply(422, ['ok' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Data channel error [' . $scope . ']: ' . $exception::class . ': ' . $exception->getMessage());
    $reply(503, ['ok' => false, 'message' => 'Не удалось выполнить операцию с каналом данных.']);
}
