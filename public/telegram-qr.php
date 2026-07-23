<?php
declare(strict_types=1);

use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;

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

$projectRoot = dirname(__DIR__);
$autoload = $projectRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $reply(503, ['ok' => false, 'message' => 'Зависимости проекта не установлены.']);
}
require_once $autoload;

$sessionDir = $projectRoot . '/storage/telegram-sessions';
$runtimeDir = $projectRoot . '/storage/madeline-runtime';
foreach ([$sessionDir, $runtimeDir] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        $reply(503, ['ok' => false, 'message' => 'Не удалось подготовить хранилище Telegram.']);
    }
    if (!is_writable($directory)) {
        $reply(503, ['ok' => false, 'message' => 'Хранилище Telegram недоступно для записи.']);
    }
}

// MadelineProto creates MadelineProto.log in the current working directory by default.
// Never let a web request try to write that file into public/.
if (!chdir($runtimeDir)) {
    $reply(503, ['ok' => false, 'message' => 'Не удалось открыть рабочий каталог Telegram.']);
}

$sessionKey = hash('sha256', $accountId . ':' . $apiIdRaw);
$sessionPath = $sessionDir . '/account-' . $sessionKey . '.madeline';

try {
    $settings = (new AppInfo())
        ->setApiId((int) $apiIdRaw)
        ->setApiHash($apiHash)
        ->setDeviceModel('SkyGuardian VPS')
        ->setAppVersion('SkyGuardian 1.0')
        ->setLangCode('ru')
        ->setSystemLangCode('ru');

    $telegram = new API($sessionPath, $settings);

    if ($operation === '2fa') {
        $password = (string) ($_POST['password'] ?? '');
        if ($password === '') {
            $reply(422, ['ok' => false, 'message' => 'Введите пароль двухэтапной аутентификации.']);
        }
        $telegram->complete2faLogin($password);
    }

    $qr = $telegram->qrLogin();
    if ($qr !== null) {
        $reply(200, [
            'ok' => true,
            'logged_in' => false,
            'needs_2fa' => false,
            'svg' => $qr->getQRSvg(420, 3),
            'expires_in' => $qr->expiresIn(),
        ]);
    }

    $authorization = $telegram->getAuthorization();
    if ($authorization === API::WAITING_PASSWORD) {
        $reply(200, [
            'ok' => true,
            'logged_in' => false,
            'needs_2fa' => true,
            'hint' => (string) $telegram->getHint(),
        ]);
    }

    $self = $telegram->getSelf();
    $reply(200, [
        'ok' => true,
        'logged_in' => true,
        'needs_2fa' => false,
        'account' => [
            'id' => (string) ($self['id'] ?? ''),
            'first_name' => (string) ($self['first_name'] ?? ''),
            'last_name' => (string) ($self['last_name'] ?? ''),
            'username' => (string) ($self['username'] ?? ''),
            'phone' => (string) ($self['phone'] ?? ''),
        ],
    ]);
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
    $reply(503, ['ok' => false, 'message' => 'Не удалось получить QR-код Telegram. Проверьте журналы сервера.']);
}
