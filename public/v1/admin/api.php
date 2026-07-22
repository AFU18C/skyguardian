<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use SkyGuardian\DataChannel\ChannelRepository;
use SkyGuardian\DataChannel\ChannelValidator;
use SkyGuardian\Http\Csrf;
use SkyGuardian\Http\SessionAuth;
use SkyGuardian\Moderation\ModerationSettingsRepository;
use SkyGuardian\Telegram\AccountRepository;
use SkyGuardian\Telegram\BotConfigRepository;
use SkyGuardian\Worker\WorkerStatusRepository;

SessionAuth::requireLogin();
header('Content-Type: application/json; charset=utf-8');
$action = (string) ($_GET['action'] ?? 'overview');
$bot = new BotConfigRepository($store);
$moderation = new ModerationSettingsRepository($store);
$channels = new ChannelRepository($store);
$accounts = new AccountRepository($store);
$workers = new WorkerStatusRepository($store);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $data = match ($action) {
            'bot' => array_replace($bot->get(), ['token' => $bot->get()['token'] !== '' ? 'configured' : '']),
            'moderation' => $moderation->get(),
            'accounts' => array_map(static function (array $item): array { unset($item['api_hash'], $item['session_path']); return $item; }, $accounts->all()),
            'channels' => ['news' => $channels->all('news'), 'alerts' => $channels->all('alerts')],
            'workers' => ['news' => $workers->get('news'), 'alerts' => $workers->get('alerts')],
            default => [
                'bot' => ['enabled' => (bool) ($bot->get()['enabled'] ?? false), 'mode' => $bot->get()['mode'] ?? 'webhook'],
                'accounts' => count($accounts->all()),
                'channels' => count($channels->all('news')) + count($channels->all('alerts')),
                'workers' => ['news' => $workers->get('news'), 'alerts' => $workers->get('alerts')],
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
            if (($input['token'] ?? '') === '' || ($input['token'] ?? '') === 'configured') $input['token'] = $current['token'];
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
            $accounts->save($input);
            break;
        case 'account-delete':
            $accounts->delete((string) ($input['id'] ?? ''));
            break;
        default:
            throw new InvalidArgumentException('Unknown action');
    }
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code($e instanceof InvalidArgumentException ? 422 : 500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
