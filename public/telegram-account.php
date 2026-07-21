<?php
declare(strict_types=1);

use Amp\CancelledException;
use Amp\TimeoutCancellation;
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
header('Cache-Control: no-store');

$reply = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SESSION['admin_authenticated'] ?? false) !== true) {
    $reply(401, ['ok' => false, 'message' => 'Требуется авторизация.']);
}

$storageDir = dirname(__DIR__) . '/storage';
$accountsFile = $storageDir . '/telegram-accounts.json';
$sessionsDir = $storageDir . '/telegram-sessions';
$madelineLog = $sessionsDir . '/MadelineProto.log';
$autoload = dirname(__DIR__) . '/vendor/autoload.php';

$ensureStorage = static function () use ($storageDir, $sessionsDir, $madelineLog): void {
    foreach ([$storageDir, $sessionsDir] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось подготовить хранилище Telegram.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('Хранилище Telegram недоступно для записи.');
        }
    }
    if (!is_file($madelineLog) && touch($madelineLog) === false) {
        throw new RuntimeException('Не удалось подготовить журнал Telegram.');
    }
    chmod($madelineLog, 0600);
    ini_set('log_errors', '1');
    ini_set('error_log', $madelineLog);
};

$readAccounts = static function () use ($accountsFile): array {
    if (!is_file($accountsFile)) return [];
    $handle = fopen($accountsFile, 'rb');
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

$writeAccounts = static function (array $accounts) use ($accountsFile, $storageDir): void {
    $json = json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temp = tempnam($storageDir, '.telegram-accounts-');
    if ($temp === false) throw new RuntimeException('Не удалось сохранить настройки Telegram.');
    try {
        if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать настройки Telegram.');
        }
        chmod($temp, 0600);
        if (!rename($temp, $accountsFile)) {
            throw new RuntimeException('Не удалось применить настройки Telegram.');
        }
        chmod($accountsFile, 0600);
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
};

$publicAccount = static function (array $account): array {
    return [
        'id' => (string) ($account['id'] ?? ''),
        'name' => (string) ($account['name'] ?? ''),
        'api_id' => (string) ($account['api_id'] ?? ''),
        'has_api_hash' => ((string) ($account['api_hash'] ?? '')) !== '',
        'connected' => (bool) ($account['connected'] ?? false),
        'enabled' => (bool) ($account['enabled'] ?? true),
        'user' => is_array($account['user'] ?? null) ? $account['user'] : null,
        'connected_at' => $account['connected_at'] ?? null,
        'updated_at' => $account['updated_at'] ?? null,
    ];
};

$findAccountIndex = static function (array $accounts, string $id): int {
    foreach ($accounts as $index => $account) {
        if ((string) ($account['id'] ?? '') === $id) return (int) $index;
    }
    return -1;
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    $items = scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $removeTree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
};

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'bootstrap');

if ($action === 'bootstrap' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $ensureStorage();
    $accounts = array_map($publicAccount, $readAccounts());
    $reply(200, [
        'ok' => true,
        'csrf' => (string) ($_SESSION['csrf_token'] ?? ''),
        'accounts' => array_values($accounts),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reply(405, ['ok' => false, 'message' => 'Метод не поддерживается.']);
}

$csrf = (string) ($_POST['_token'] ?? '');
if ($csrf === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    $reply(419, ['ok' => false, 'message' => 'Сессия устарела. Обновите страницу.']);
}

try {
    $ensureStorage();
    $accounts = $readAccounts();

    if ($action === 'check') {
        $id = trim((string) ($_POST['id'] ?? ''));
        if ($id === '') $id = bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9-]{16,64}$/i', $id)) throw new InvalidArgumentException('Некорректный идентификатор подключения.');

        $name = trim((string) ($_POST['name'] ?? ''));
        $apiIdRaw = trim((string) ($_POST['api_id'] ?? ''));
        $apiHash = trim((string) ($_POST['api_hash'] ?? ''));
        $index = $findAccountIndex($accounts, $id);
        $existing = $index >= 0 ? (array) $accounts[$index] : [];
        if ($apiHash === '') $apiHash = (string) ($existing['api_hash'] ?? '');

        if ($name === '' || mb_strlen($name) > 80) throw new InvalidArgumentException('Введите название подключения.');
        if (!preg_match('/^\d{4,12}$/', $apiIdRaw) || (int) $apiIdRaw <= 0) throw new InvalidArgumentException('API ID имеет неверный формат.');
        if (!preg_match('/^[a-f0-9]{32}$/i', $apiHash)) throw new InvalidArgumentException('API Hash должен содержать 32 шестнадцатеричных символа.');
        if (!is_file($autoload)) throw new RuntimeException('MadelineProto ещё не установлен на сервере.');
        require_once $autoload;

        $account = array_merge($existing, [
            'id' => $id,
            'name' => $name,
            'api_id' => (int) $apiIdRaw,
            'api_hash' => strtolower($apiHash),
            'enabled' => (bool) ($existing['enabled'] ?? true),
            'connected' => (bool) ($existing['connected'] ?? false),
            'updated_at' => gmdate(DATE_ATOM),
        ]);
        if ($index >= 0) $accounts[$index] = $account; else $accounts[] = $account;
        $writeAccounts($accounts);

        $settings = (new AppInfo())->setApiId((int) $account['api_id'])->setApiHash((string) $account['api_hash']);
        $api = new API($sessionsDir . '/' . $id . '.madeline', $settings);
        $qr = $api->qrLogin();
        $loggedIn = $qr === null && $api->getAuthorization() !== API::WAITING_PASSWORD;
        if ($loggedIn) {
            $self = (array) $api->getSelf();
            $account['connected'] = true;
            $account['connected_at'] = $account['connected_at'] ?? gmdate(DATE_ATOM);
            $account['user'] = [
                'id' => (string) ($self['id'] ?? ''),
                'name' => trim((string) (($self['first_name'] ?? '') . ' ' . ($self['last_name'] ?? ''))),
                'username' => (string) ($self['username'] ?? ''),
                'phone' => (string) ($self['phone'] ?? ''),
            ];
            $index = $findAccountIndex($accounts, $id);
            $accounts[$index] = $account;
            $writeAccounts($accounts);
        }
        $reply(200, ['ok' => true, 'message' => 'Telegram API принят сервером.', 'account' => $publicAccount($account)]);
    }

    $id = trim((string) ($_POST['id'] ?? ''));
    $index = $findAccountIndex($accounts, $id);
    if ($index < 0) throw new InvalidArgumentException('Подключение не найдено. Сначала проверьте API.');
    $account = (array) $accounts[$index];

    if ($action === 'toggle') {
        $account['enabled'] = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $account['updated_at'] = gmdate(DATE_ATOM);
        $accounts[$index] = $account;
        $writeAccounts($accounts);
        $reply(200, ['ok' => true, 'message' => $account['enabled'] ? 'Технический аккаунт включён.' : 'Технический аккаунт выключен.', 'account' => $publicAccount($account)]);
    }

    if ($action === 'delete') {
        array_splice($accounts, $index, 1);
        $writeAccounts($accounts);
        $removeTree($sessionsDir . '/' . $id . '.madeline');
        foreach (glob($sessionsDir . '/' . $id . '.madeline*') ?: [] as $sessionPath) $removeTree($sessionPath);
        $reply(200, ['ok' => true, 'message' => 'Технический аккаунт удалён.']);
    }

    if (!is_file($autoload)) throw new RuntimeException('MadelineProto ещё не установлен на сервере.');
    require_once $autoload;
    $settings = (new AppInfo())->setApiId((int) $account['api_id'])->setApiHash((string) $account['api_hash']);
    $api = new API($sessionsDir . '/' . $id . '.madeline', $settings);

    $finishLogin = static function (API $api, array &$account, array &$accounts, int $index) use ($writeAccounts, $publicAccount, $reply): never {
        $self = (array) $api->getSelf();
        $account['connected'] = true;
        $account['connected_at'] = $account['connected_at'] ?? gmdate(DATE_ATOM);
        $account['updated_at'] = gmdate(DATE_ATOM);
        $account['user'] = [
            'id' => (string) ($self['id'] ?? ''),
            'name' => trim((string) (($self['first_name'] ?? '') . ' ' . ($self['last_name'] ?? ''))),
            'username' => (string) ($self['username'] ?? ''),
            'phone' => (string) ($self['phone'] ?? ''),
        ];
        $accounts[$index] = $account;
        $writeAccounts($accounts);
        $reply(200, ['ok' => true, 'logged_in' => true, 'needs_2fa' => false, 'message' => 'Telegram-аккаунт подключён.', 'account' => $publicAccount($account)]);
    };

    if ($action === 'password') {
        $password = (string) ($_POST['password'] ?? '');
        if ($password === '') throw new InvalidArgumentException('Введите пароль двухэтапной аутентификации.');
        $api->complete2faLogin($password);
        $finishLogin($api, $account, $accounts, $index);
    }

    if (!in_array($action, ['qr', 'wait'], true)) throw new InvalidArgumentException('Неизвестная операция.');

    try {
        $qr = $api->qrLogin();
        if ($action === 'wait' && $qr !== null) {
            $qr = $qr->waitForLoginOrQrCodeExpiration(new TimeoutCancellation(5.0));
        }
    } catch (CancelledException) {
        $qr = $api->qrLogin();
    }

    if ($qr !== null) {
        $reply(200, [
            'ok' => true,
            'logged_in' => false,
            'needs_2fa' => false,
            'svg' => $qr->getQRSvg(400, 2),
            'message' => 'Отсканируйте QR-код в Telegram.',
        ]);
    }

    if ($api->getAuthorization() === API::WAITING_PASSWORD) {
        $reply(200, [
            'ok' => true,
            'logged_in' => false,
            'needs_2fa' => true,
            'hint' => (string) $api->getHint(),
            'message' => 'Требуется пароль двухэтапной аутентификации.',
        ]);
    }

    $finishLogin($api, $account, $accounts, $index);
} catch (InvalidArgumentException $exception) {
    $reply(422, ['ok' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Telegram account error: ' . $exception::class . ': ' . $exception->getMessage());
    $message = $exception->getMessage();
    $lower = strtolower($message);
    if (str_contains($lower, 'api_id') || str_contains($lower, 'api hash') || str_contains($lower, 'api_hash')) {
        $message = 'Telegram отклонил API ID или API Hash.';
    } elseif (str_contains($lower, 'password')) {
        $message = 'Неверный пароль двухэтапной аутентификации.';
    } elseif ($message === '') {
        $message = 'Не удалось подключить Telegram-аккаунт.';
    }
    $reply(503, ['ok' => false, 'message' => $message]);
}
