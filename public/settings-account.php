<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
$storageFile = $root . '/storage/skyguardian.json';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function loadData(string $file): array
{
    if (!is_file($file)) {
        return [
            'news' => ['channels' => [], 'settings' => ['accounts' => []]],
            'alerts' => ['channels' => [], 'settings' => ['accounts' => []]],
        ];
    }

    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveData(string $file, array $data): void
{
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $file);
}

if (($_SESSION['authenticated'] ?? false) !== true) {
    respond(['ok' => false, 'message' => 'Требуется авторизация.'], 401);
}

$section = (string) ($_REQUEST['section'] ?? '');
if (!in_array($section, ['news', 'alerts'], true)) {
    respond(['ok' => false, 'message' => 'Неизвестный раздел.'], 422);
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

$data = loadData($storageFile);
$data[$section] = is_array($data[$section] ?? null) ? $data[$section] : [];
$data[$section]['settings'] = is_array($data[$section]['settings'] ?? null) ? $data[$section]['settings'] : [];
$data[$section]['settings']['accounts'] = is_array($data[$section]['settings']['accounts'] ?? null)
    ? $data[$section]['settings']['accounts']
    : [];

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'GET') {
    respond([
        'ok' => true,
        'csrf' => (string) $_SESSION['csrf'],
        'accounts' => $data[$section]['settings']['accounts'],
    ]);
}

if ($method !== 'POST') {
    respond(['ok' => false, 'message' => 'Метод не поддерживается.'], 405);
}

$csrf = (string) ($_POST['csrf'] ?? '');
if ($csrf === '' || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
    respond(['ok' => false, 'message' => 'Сессия устарела. Обновите страницу.'], 419);
}

$action = (string) ($_POST['action'] ?? 'save');
$accounts = &$data[$section]['settings']['accounts'];

if ($action === 'save') {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['id'] ?? '')) ?: bin2hex(random_bytes(8));
    $name = trim((string) ($_POST['name'] ?? ''));
    $apiId = trim((string) ($_POST['api_id'] ?? ''));
    $apiHash = trim((string) ($_POST['api_hash'] ?? ''));

    if ($name === '' || $apiId === '' || $apiHash === '') {
        respond(['ok' => false, 'message' => 'Заполните название, API ID и API Hash.'], 422);
    }

    $account = [
        'id' => $id,
        'name' => $name,
        'api_id' => $apiId,
        'api_hash' => $apiHash,
        'active' => ($_POST['active'] ?? '1') === '1',
        'connected' => false,
    ];

    $found = false;
    foreach ($accounts as $index => $existing) {
        if ((string) ($existing['id'] ?? '') === $id) {
            $account['connected'] = (bool) ($existing['connected'] ?? false);
            foreach (['telegram_id', 'telegram_username', 'telegram_name'] as $field) {
                if (isset($existing[$field])) {
                    $account[$field] = $existing[$field];
                }
            }
            $accounts[$index] = $account;
            $found = true;
            break;
        }
    }

    if (!$found) {
        if (count($accounts) >= 10) {
            respond(['ok' => false, 'message' => 'Достигнут лимит технических аккаунтов.'], 422);
        }
        $accounts[] = $account;
    }

    saveData($storageFile, $data);
    respond(['ok' => true, 'message' => 'Технический аккаунт сохранён.', 'account' => $account]);
}

$id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['id'] ?? ''));
if ($id === '') {
    respond(['ok' => false, 'message' => 'Аккаунт не найден.'], 422);
}

if ($action === 'delete') {
    $accounts = array_values(array_filter($accounts, static fn(array $account): bool => (string) ($account['id'] ?? '') !== $id));
    saveData($storageFile, $data);
    respond(['ok' => true, 'message' => 'Технический аккаунт удалён.']);
}

if ($action === 'toggle') {
    foreach ($accounts as &$account) {
        if ((string) ($account['id'] ?? '') === $id) {
            $account['active'] = ($_POST['active'] ?? '0') === '1';
            break;
        }
    }
    unset($account);
    saveData($storageFile, $data);
    respond(['ok' => true]);
}

respond(['ok' => false, 'message' => 'Неизвестное действие.'], 422);
