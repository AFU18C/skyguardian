<?php
declare(strict_types=1);

use danog\MadelineProto\API;
use danog\MadelineProto\Logger as MadelineLogger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger as LoggerSettings;

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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    $reply(405, ['ok' => false, 'message' => 'Разрешён только POST-запрос.']);
}
if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
    $reply(419, ['ok' => false, 'message' => 'Сессия устарела. Обновите страницу.']);
}

$apiIdRaw = trim((string) ($_POST['api_id'] ?? ''));
$apiHash = strtolower(trim((string) ($_POST['api_hash'] ?? '')));
$accountId = trim((string) ($_POST['account_id'] ?? ''));
$operation = trim((string) ($_POST['operation'] ?? 'status'));
$connectionName = trim((string) ($_POST['name'] ?? ''));

if (!preg_match('/^[1-9]\d{3,11}$/', $apiIdRaw)) {
    $reply(422, ['ok' => false, 'message' => 'API ID имеет неверный формат.']);
}
if (!preg_match('/^[a-f0-9]{32}$/', $apiHash)) {
    $reply(422, ['ok' => false, 'message' => 'API Hash должен содержать 32 шестнадцатеричных символа.']);
}
if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $accountId)) {
    $reply(422, ['ok' => false, 'message' => 'Некорректный идентификатор подключения.']);
}
if (!in_array($operation, ['status', '2fa'], true)) {
    $reply(422, ['ok' => false, 'message' => 'Неизвестная операция подключения.']);
}

session_write_close();
@set_time_limit(25);

$projectRoot = dirname(__DIR__);
$sessionDir = $projectRoot . '/storage/telegram-sessions';
$runtimeDir = $projectRoot . '/storage/madeline-runtime';
$accountsDir = $projectRoot . '/storage/technical-accounts';
foreach ([$sessionDir, $runtimeDir, $accountsDir] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        $reply(503, ['ok' => false, 'message' => 'Не удалось подготовить хранилище Telegram.']);
    }
    if (!is_writable($directory)) {
        $reply(503, ['ok' => false, 'message' => 'Хранилище Telegram недоступно для записи.']);
    }
}

$runtimeLog = $runtimeDir . '/MadelineProto.log';
if (!is_file($runtimeLog) && file_put_contents($runtimeLog, '') === false) {
    $reply(503, ['ok' => false, 'message' => 'Не удалось создать журнал Telegram.']);
}
@chmod($runtimeLog, 0660);
if (!is_writable($runtimeLog) || !chdir($runtimeDir)) {
    $reply(503, ['ok' => false, 'message' => 'Служебный каталог Telegram недоступен.']);
}
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', $runtimeLog);

$autoload = $projectRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $reply(503, ['ok' => false, 'message' => 'Зависимости проекта не установлены.']);
}
require_once $autoload;

$sessionKey = hash('sha256', $accountId . ':' . $apiIdRaw);
$sessionPath = $sessionDir . '/account-' . $sessionKey . '.madeline';
$accountsFile = $accountsDir . '/telegram.json';

$saveConnectedAccount = static function (array $self) use ($accountsFile, $accountId, $connectionName, $apiIdRaw, $apiHash): array {
    $handle = fopen($accountsFile, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Не удалось открыть единое хранилище технических аккаунтов.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Не удалось заблокировать хранилище технических аккаунтов.');
        }
        rewind($handle);
        $decoded = json_decode((string) stream_get_contents($handle), true);
        $items = is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
        $telegramName = trim((string) (($self['first_name'] ?? '') . ' ' . ($self['last_name'] ?? '')));
        $item = [
            'id' => $accountId,
            'name' => mb_substr($connectionName !== '' ? $connectionName : ('Telegram ' . $apiIdRaw), 0, 500),
            'api_id' => $apiIdRaw,
            'api_hash' => $apiHash,
            'connected' => true,
            'enabled' => true,
            'telegram_id' => (string) ($self['id'] ?? ''),
            'telegram_name' => $telegramName !== '' ? $telegramName : (string) ($self['username'] ?? ''),
            'telegram_username' => (string) ($self['username'] ?? ''),
            'phone' => (string) ($self['phone'] ?? ''),
            'connected_at' => gmdate('c'),
        ];
        $found = false;
        foreach ($items as $index => $existing) {
            if (($existing['id'] ?? null) === $accountId) {
                $item['enabled'] = (bool) ($existing['enabled'] ?? true);
                $items[$index] = array_merge($existing, $item);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $items[] = $item;
        }
        $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Не удалось сериализовать технический аккаунт.');
        }
        ftruncate($handle, 0);
        rewind($handle);
        if (fwrite($handle, $json) === false || !fflush($handle)) {
            throw new RuntimeException('Не удалось сохранить технический аккаунт.');
        }
        @chmod($accountsFile, 0660);
        flock($handle, LOCK_UN);
        return $item;
    } finally {
        fclose($handle);
    }
};

$accountPayload = static fn(array $self): array => [
    'id' => (string) ($self['id'] ?? ''),
    'first_name' => (string) ($self['first_name'] ?? ''),
    'last_name' => (string) ($self['last_name'] ?? ''),
    'username' => (string) ($self['username'] ?? ''),
    'phone' => (string) ($self['phone'] ?? ''),
];

try {
    $appInfo = (new AppInfo())
        ->setApiId((int) $apiIdRaw)
        ->setApiHash($apiHash)
        ->setDeviceModel('SkyGuardian VPS')
        ->setAppVersion('SkyGuardian 1.0')
        ->setLangCode('ru')
        ->setSystemLangCode('ru');

    $logger = (new LoggerSettings())
        ->setType(MadelineLogger::LOGGER_FILE)
        ->setExtra($runtimeLog)
        ->setLevel(MadelineLogger::LEVEL_ERROR)
        ->setMaxSize(10 * 1024 * 1024);

    $settings = (new Settings())
        ->setAppInfo($appInfo)
        ->setLogger($logger);

    $telegram = new API($sessionPath, $settings);

    if ($operation === '2fa') {
        $password = (string) ($_POST['password'] ?? '');
        if ($password === '') {
            $reply(422, ['ok' => false, 'message' => 'Введите пароль двухэтапной аутентификации.']);
        }
        $telegram->complete2faLogin($password);
    }

    $authorization = $telegram->getAuthorization();
    if ($authorization === API::LOGGED_IN) {
        $self = $telegram->getSelf();
        if (!is_array($self)) {
            throw new RuntimeException('Telegram-сессия авторизована, но данные аккаунта недоступны.');
        }
        $stored = $saveConnectedAccount($self);
        $reply(200, ['ok' => true, 'logged_in' => true, 'needs_2fa' => false, 'account' => $accountPayload($self), 'stored' => $stored]);
    }
    if ($authorization === API::WAITING_PASSWORD) {
        $reply(200, [
            'ok' => true,
            'logged_in' => false,
            'needs_2fa' => true,
            'hint' => (string) $telegram->getHint(),
        ]);
    }

    $qr = $telegram->qrLogin();
    if ($qr !== null) {
        $reply(200, [
            'ok' => true,
            'logged_in' => false,
            'needs_2fa' => false,
            'svg' => $qr->getQRSvg(420, 3),
            'link' => (string) $qr->link,
            'expires_in' => $qr->expiresIn(),
        ]);
    }

    $authorization = $telegram->getAuthorization();
    if ($authorization === API::WAITING_PASSWORD) {
        $reply(200, ['ok' => true, 'logged_in' => false, 'needs_2fa' => true, 'hint' => (string) $telegram->getHint()]);
    }
    $self = $telegram->getSelf();
    if (!is_array($self)) {
        $reply(200, ['ok' => true, 'logged_in' => false, 'needs_2fa' => false, 'pending' => true]);
    }
    $stored = $saveConnectedAccount($self);
    $reply(200, ['ok' => true, 'logged_in' => true, 'needs_2fa' => false, 'account' => $accountPayload($self), 'stored' => $stored]);
} catch (Throwable $exception) {
    error_log('Telegram QR login error: ' . $exception::class . ': ' . $exception->getMessage());
    $message = strtolower($exception->getMessage());
    if (str_contains($message, 'api_id') || str_contains($message, 'api hash') || str_contains($message, 'api_hash')) {
        $reply(422, ['ok' => false, 'message' => 'Telegram отклонил API ID или API Hash.']);
    }
    if (str_contains($message, 'password_hash_invalid')) {
        $reply(422, ['ok' => false, 'message' => 'Неверный пароль двухэтапной аутентификации.']);
    }
    if (str_contains($message, 'flood_wait')) {
        $reply(429, ['ok' => false, 'message' => 'Telegram временно ограничил попытки входа. Подождите и попробуйте позже.']);
    }
    if (str_contains($message, 'permission denied')) {
        $reply(503, ['ok' => false, 'message' => 'Сервер не может записать служебные файлы Telegram.']);
    }
    $reply(503, ['ok' => false, 'message' => 'Не удалось завершить подключение Telegram.']);
}
