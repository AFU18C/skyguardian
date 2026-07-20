<?php

declare(strict_types=1);

use SkyGuardian\Telegram\QrLoginService;

session_start();
header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SESSION['authenticated'] ?? false) !== true) {
    respond(['ok' => false, 'message' => 'Требуется авторизация.'], 401);
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(['ok' => false, 'message' => 'Метод не поддерживается.'], 405);
}

$csrf = (string) ($_POST['csrf'] ?? '');
$sessionCsrf = (string) ($_SESSION['csrf'] ?? '');
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    respond(['ok' => false, 'message' => 'Сессия устарела. Обновите страницу.'], 419);
}

$accountId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['account_id'] ?? ''));
$apiId = filter_var($_POST['api_id'] ?? null, FILTER_VALIDATE_INT);
$apiHash = trim((string) ($_POST['api_hash'] ?? ''));
$action = (string) ($_POST['action'] ?? 'status');

if ($accountId === '' || !$apiId || $apiHash === '') {
    respond(['ok' => false, 'message' => 'Сначала сохраните аккаунт и заполните API ID и API Hash.'], 422);
}

$sessionDirectory = $root . '/storage/telegram-sessions';
if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0775, true) && !is_dir($sessionDirectory)) {
    respond(['ok' => false, 'message' => 'Не удалось создать каталог Telegram-сессий.'], 500);
}

$sessionPath = $sessionDirectory . '/' . $accountId;

try {
    $service = new QrLoginService($sessionPath, (int) $apiId, $apiHash);

    if ($action === '2fa') {
        $password = (string) ($_POST['password'] ?? '');
        if ($password === '') {
            respond(['ok' => false, 'message' => 'Введите пароль двухэтапной аутентификации.'], 422);
        }
        $service->completeTwoFactorLogin($password);
    }

    $state = $service->getQrCode($action === 'status');

    if (($state['logged_in'] ?? false) === true && ($state['needs_2fa'] ?? false) === false) {
        $profile = $service->getAccount();
        $storageFile = $root . '/storage/skyguardian.json';
        $data = is_file($storageFile)
            ? json_decode((string) file_get_contents($storageFile), true)
            : [];
        $data = is_array($data) ? $data : [];
        $data['accounts'] = is_array($data['accounts'] ?? null) ? $data['accounts'] : [];

        foreach ($data['accounts'] as &$account) {
            if ((string) ($account['id'] ?? '') !== $accountId) {
                continue;
            }
            $account['connected'] = true;
            $account['telegram_id'] = (string) ($profile['id'] ?? '');
            $account['telegram_username'] = (string) ($profile['username'] ?? '');
            $account['telegram_name'] = trim((string) (($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')));
            break;
        }
        unset($account);

        $tmp = $storageFile . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($tmp, $storageFile);

        respond([
            'ok' => true,
            'logged_in' => true,
            'needs_2fa' => false,
            'account' => [
                'id' => (string) ($profile['id'] ?? ''),
                'username' => (string) ($profile['username'] ?? ''),
                'name' => trim((string) (($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''))),
            ],
        ]);
    }

    respond(['ok' => true] + $state);
} catch (Throwable $exception) {
    error_log('SkyGuardian Telegram QR error: ' . $exception->getMessage());
    respond([
        'ok' => false,
        'message' => 'Не удалось подключиться к Telegram. Проверьте API ID, API Hash и повторите попытку.',
    ], 500);
}
