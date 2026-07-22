#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SkyGuardian\DataChannel\ChannelRepository;
use SkyGuardian\DataChannel\ChannelValidator;
use SkyGuardian\Moderation\ModerationSettingsRepository;
use SkyGuardian\Storage\JsonStore;
use SkyGuardian\Telegram\AccountRepository;
use SkyGuardian\Telegram\BotConfigRepository;
use SkyGuardian\Telegram\QrLoginService;

$failures = [];
$temp = sys_get_temp_dir() . '/skyguardian-regression-' . bin2hex(random_bytes(5));
mkdir($temp, 0700, true);
$store = new JsonStore($temp);

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$throws = static function (callable $callback, string $class, string $message) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message . ' (exception was not thrown)';
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            $failures[] = $message . ' (got ' . $e::class . ')';
        }
    }
};

try {
    $bot = new BotConfigRepository($store);
    $bot->save(['enabled' => true, 'mode' => 'polling', 'token' => 'secret', 'webhook_secret' => 'hook']);
    $assert(($bot->get()['mode'] ?? null) === 'polling', 'Bot configuration was not persisted');

    $moderation = new ModerationSettingsRepository($store);
    $moderation->save([
        'anti_spam' => true,
        'link_filter' => true,
        'admin_bypass' => false,
        'forbidden_words' => ['spam', ' test ', ''],
        'mute_seconds' => 90,
    ]);
    $settings = $moderation->get();
    $assert(($settings['anti_spam'] ?? false) === true, 'Moderation anti-spam setting failed');
    $assert(($settings['mute_seconds'] ?? null) === 90, 'Moderation mute duration failed');

    $validator = new ChannelValidator();
    $valid = $validator->validate([
        'id' => 'news-main',
        'scope' => 'news',
        'source' => '@source',
        'destination' => '@destination',
        'account_id' => 'tech-1',
        'format' => 'text_without_links',
        'fetch_start' => 'last_10',
        'frequency' => 0,
        'frequency_unit' => 'invalid',
        'enabled' => 1,
    ]);
    $assert($valid['frequency'] === 1, 'Channel frequency lower bound failed');
    $assert($valid['frequency_unit'] === 'minutes', 'Channel frequency unit fallback failed');
    $throws(fn() => $validator->validate(['id' => 'broken']), InvalidArgumentException::class, 'Incomplete channel accepted');
    $throws(fn() => $validator->validate([
        'id' => 'bad-format', 'scope' => 'news', 'source' => 'a', 'destination' => 'b', 'account_id' => 'c', 'format' => 'html',
    ]), InvalidArgumentException::class, 'Invalid channel format accepted');

    $channels = new ChannelRepository($store);
    $channels->save($valid);
    $assert(count($channels->all('news')) === 1, 'Channel repository save failed');
    $channels->delete('news-main');
    $assert($channels->all('news') === [], 'Channel repository delete failed');

    $accounts = new AccountRepository($store);
    $accounts->save([
        'id' => 'tech-1',
        'api_id' => 12345,
        'api_hash' => 'hash',
        'session_path' => 'storage/v1/telegram-sessions/tech-1.madeline',
        'enabled' => false,
        'connected_user' => null,
    ]);
    $assert(count($accounts->all()) === 1, 'Account repository save failed');
    $accounts->delete('tech-1');
    $assert($accounts->all() === [], 'Account repository delete failed');

    $qr = new QrLoginService($temp);
    $throws(fn() => $qr->qr([]), InvalidArgumentException::class, 'QR login accepted missing credentials');
    $throws(fn() => $qr->complete2fa([
        'api_id' => 1,
        'api_hash' => 'hash',
        'session_path' => 'session/test.madeline',
    ], ''), InvalidArgumentException::class, 'QR 2FA accepted an empty password');
} catch (Throwable $e) {
    $failures[] = $e::class . ': ' . $e->getMessage();
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item === '.' || $item === '..') continue;
                $remove($path . '/' . $item);
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    };
    $remove($temp);
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", array_unique($failures)) . "\n");
    exit(1);
}

fwrite(STDOUT, "Regression tests passed\n");
