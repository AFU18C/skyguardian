<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use SkyGuardian\DataChannel\ChannelRepository;
use SkyGuardian\DataChannel\ChannelValidator;
use SkyGuardian\Http\Csrf;
use SkyGuardian\Http\SessionAuth;
use SkyGuardian\Moderation\ModerationSettingsRepository;
use SkyGuardian\Telegram\AccountRepository;
use SkyGuardian\Telegram\BotApiClient;
use SkyGuardian\Telegram\BotConfigRepository;
use SkyGuardian\Telegram\QrLoginService;
use SkyGuardian\Telegram\TelegramAdminService;

SessionAuth::requireLogin();
header('Content-Type: application/json; charset=utf-8');
$action = (string) ($_GET['action'] ?? 'overview');
$bot = new BotConfigRepository($store);
$moderation = new ModerationSettingsRepository($store);
$channels = new ChannelRepository($store);
$accounts = new AccountRepository($store);

$redactAccount = static function (array $item): array {
    unset($item['api_hash']);
    $item['session_configured'] = trim((string) ($item['session_path'] ?? '')) !== '';
    unset($item['session_path']);
    return $item;
};
$findAccount = static function (string $id) use ($accounts): array {
    foreach ($accounts->all() as $candidate) {
        if (($candidate['id'] ?? null) === $id) return $candidate;
    }
    throw new InvalidArgumentException('Техаккаунт не найден.');
};
$saveConnected = static function (array $account, array $user) use ($accounts): void {
    $account['connected_user'] = $user;
    $account['enabled'] = true;
    $account['updated_at'] = gmdate(DATE_ATOM);
    $accounts->save($account);
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $config = $bot->get();
        $safeBot = $config;
        $safeBot['token'] = trim((string) ($config['token'] ?? '')) !== '' ? 'configured' : '';
        $safeBot['webhook_secret'] = trim((string) ($config['webhook_secret'] ?? '')) !== '' ? 'configured' : '';
        $data = match ($action) {
            'bot' => $safeBot,
            'moderation' => $moderation->get(),
            'accounts' => array_map($redactAccount, $accounts->all()),
            'channels' => ['news' => $channels->all('news'), 'alerts' => $channels->all('alerts')],
            'workers' => ['channels' => $store->read('channel_states')],
            default => [
                'bot' => ['enabled' => (bool) ($config['enabled'] ?? false), 'mode' => $config['mode'] ?? 'webhook'],
                'accounts' => count($accounts->all()),
                'channels' => count($channels->all('news')) + count($channels->all('alerts')),
                'workers' => $store->read('channel_states'),
            ],
        };
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new RuntimeException('Method not allowed'); }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null);
    Csrf::requireValid(is_string($token) ? $token : null);
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;

    switch ($action) {
        case 'bot':
            $current = $bot->get();
            if (($input['token'] ?? '') === '' || ($input['token'] ?? '') === 'configured') $input['token'] = $current['token'] ?? '';
            if (($input['webhook_secret'] ?? '') === '' || ($input['webhook_secret'] ?? '') === 'configured') $input['webhook_secret'] = $current['webhook_secret'] ?? '';
            $bot->save($input);
            break;
        case 'moderation':
            $moderation->save($input);
            break;
        case 'channel-save':
            $channels->save((new ChannelValidator())->validate($input));
            break;
        case 'channel-delete':
            $channels->delete((string) ($input['id'] ?? ''));
            break;
        case 'account-save':
            $id = trim((string) ($input['id'] ?? ''));
            $apiId = (int) ($input['api_id'] ?? 0);
            $apiHash = trim((string) ($input['api_hash'] ?? ''));
            if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) || $apiId <= 0 || $apiHash === '') {
                throw new InvalidArgumentException('Invalid Telegram account configuration.');
            }
            $existing = null;
            foreach ($accounts->all() as $candidate) if (($candidate['id'] ?? null) === $id) $existing = $candidate;
            $sessionPath = 'storage/v1/telegram-sessions/' . $id . '.madeline';
            $accounts->save([
                'id' => $id,
                'api_id' => $apiId,
                'api_hash' => $apiHash === 'configured' && is_array($existing) ? (string) ($existing['api_hash'] ?? '') : $apiHash,
                'session_path' => $sessionPath,
                'enabled' => (bool) ($input['enabled'] ?? ($existing['enabled'] ?? false)),
                'connected_user' => $existing['connected_user'] ?? null,
                'updated_at' => gmdate(DATE_ATOM),
            ]);
            break;
        case 'account-qr':
            $account = $findAccount(trim((string) ($input['id'] ?? '')));
            $result = (new QrLoginService(dirname(__DIR__, 3)))->qr($account, (bool) ($input['wait'] ?? false));
            if (($result['logged_in'] ?? false) && is_array($result['user'] ?? null)) $saveConnected($account, $result['user']);
            echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        case 'account-2fa':
            $account = $findAccount(trim((string) ($input['id'] ?? '')));
            $result = (new QrLoginService(dirname(__DIR__, 3)))->complete2fa($account, (string) ($input['password'] ?? ''));
            if (is_array($result['user'] ?? null)) $saveConnected($account, $result['user']);
            echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        case 'account-delete':
            $id = (string) ($input['id'] ?? '');
            foreach ($accounts->all() as $candidate) {
                if (($candidate['id'] ?? null) !== $id) continue;
                $path = (string) ($candidate['session_path'] ?? '');
                if ($path !== '') {
                    $absolute = str_starts_with($path, '/') ? $path : dirname(__DIR__, 3) . '/' . ltrim($path, '/');
                    foreach (glob($absolute . '*') ?: [] as $file) if (is_file($file)) @unlink($file);
                }
            }
            $accounts->delete($id);
            break;
        case 'telegram-action':
            $config = $bot->get();
            $botToken = trim((string) ($config['token'] ?? ''));
            if ($botToken === '') throw new RuntimeException('Telegram bot token is not configured.');
            $telegramAction = trim((string) ($input['telegram_action'] ?? ''));
            $result = (new TelegramAdminService(new BotApiClient($botToken)))->execute($telegramAction, $input);
            echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        default:
            throw new InvalidArgumentException('Unknown action');
    }
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code($e instanceof InvalidArgumentException ? 422 : 500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
