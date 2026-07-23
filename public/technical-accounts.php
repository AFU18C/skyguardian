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

$storageDir = dirname(__DIR__) . '/storage/technical-accounts';
if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) $reply(503, ['ok'=>false,'message'=>'Не удалось подготовить хранилище аккаунтов.']);
$canonicalFile = $storageDir . '/telegram.json';

$readFile = static function (string $path): array {
    if (!is_file($path)) return [];
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
};
$identity = static function (array $item): string {
    $telegramId = trim((string) ($item['telegram_id'] ?? ''));
    if ($telegramId !== '') return 'tg:' . $telegramId;
    $apiId = trim((string) ($item['api_id'] ?? ''));
    $apiHash = strtolower(trim((string) ($item['api_hash'] ?? '')));
    if ($apiId !== '' && $apiHash !== '') return 'api:' . $apiId . ':' . $apiHash;
    return 'id:' . trim((string) ($item['id'] ?? ''));
};
$score = static function (array $item): int {
    return (!empty($item['connected']) ? 1000 : 0)
        + (!empty($item['telegram_id']) ? 200 : 0)
        + (!empty($item['phone']) ? 100 : 0)
        + (!empty($item['telegram_username']) ? 50 : 0)
        + count(array_filter($item, static fn($v) => $v !== '' && $v !== null));
};
$dedupe = static function (array $items) use ($identity, $score): array {
    $merged = [];
    foreach ($items as $item) {
        if (!is_array($item) || trim((string) ($item['id'] ?? '')) === '') continue;
        $key = $identity($item);
        if (!isset($merged[$key])) { $merged[$key] = $item; continue; }
        $existing = $merged[$key];
        $winner = $score($item) >= $score($existing) ? $item : $existing;
        $loser = $winner === $item ? $existing : $item;
        $merged[$key] = array_merge($loser, $winner);
    }
    return array_values($merged);
};
$writeItems = static function (array $items) use ($canonicalFile): void {
    $tmp = $canonicalFile . '.tmp-' . bin2hex(random_bytes(6));
    $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, $canonicalFile)) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось сохранить технические аккаунты.');
    }
    @chmod($canonicalFile, 0660);
};
$loadAll = static function () use ($storageDir, $canonicalFile, $readFile, $dedupe, $writeItems): array {
    $all = $readFile($canonicalFile);
    foreach (glob($storageDir . '/*.json') ?: [] as $legacyFile) {
        if ($legacyFile !== $canonicalFile) $all = array_merge($all, $readFile($legacyFile));
    }
    $items = $dedupe($all);
    if ($items !== $readFile($canonicalFile)) $writeItems($items);
    return $items;
};

try {
    $items = $loadAll();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') $reply(200, ['ok'=>true,'items'=>$items]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $reply(405, ['ok'=>false,'message'=>'Разрешены только GET и POST.']);
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['_token'] ?? ''))) $reply(419, ['ok'=>false,'message'=>'Сессия устарела. Обновите страницу.']);

    if (isset($_POST['items'])) {
        $submitted = json_decode((string) $_POST['items'], true);
        if (!is_array($submitted)) $reply(422, ['ok'=>false,'message'=>'Некорректные данные аккаунтов.']);
        $items = $dedupe($submitted);
        $writeItems($items);
        $reply(200, ['ok'=>true,'items'=>$items]);
    }

    $item = json_decode((string) ($_POST['item'] ?? ''), true);
    if (!is_array($item) || !preg_match('/^[A-Za-z0-9_-]{8,80}$/', (string) ($item['id'] ?? ''))) $reply(422, ['ok'=>false,'message'=>'Некорректные данные технического аккаунта.']);
    $allowed = ['id','name','api_id','api_hash','connected','enabled','telegram_id','telegram_name','telegram_username','phone','connected_at'];
    $clean = [];
    foreach ($allowed as $key) if (array_key_exists($key, $item)) $clean[$key] = in_array($key, ['connected','enabled'], true) ? (bool) $item[$key] : mb_substr((string) $item[$key], 0, 500);
    $items[] = $clean;
    $items = $dedupe($items);
    $writeItems($items);
    $reply(200, ['ok'=>true,'items'=>$items]);
} catch (Throwable $e) {
    error_log('Technical accounts error: ' . $e::class . ': ' . $e->getMessage());
    $reply(503, ['ok'=>false,'message'=>'Не удалось синхронизировать технические аккаунты.']);
}
