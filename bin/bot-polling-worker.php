#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SkyGuardian\Config\Paths;
use SkyGuardian\Storage\JsonStore;
use SkyGuardian\Telegram\BotApiClient;
use SkyGuardian\Telegram\BotConfigRepository;
use SkyGuardian\Telegram\UpdateHandler;

$paths = new Paths(dirname(__DIR__));
$paths->ensureStorage();
$store = new JsonStore($paths->storage());
$repo = new BotConfigRepository($store);

while (true) {
    try {
        $config = $repo->get();
        if (!($config['enabled'] ?? false) || ($config['mode'] ?? '') !== 'polling' || trim((string) ($config['token'] ?? '')) === '') {
            sleep(5);
            continue;
        }
        $api = new BotApiClient((string) $config['token']);
        $result = $api->call('getUpdates', [
            'offset' => (int) ($config['polling_offset'] ?? 0),
            'timeout' => 25,
            'allowed_updates' => ['message','edited_message','callback_query','chat_join_request'],
        ]);
        $handler = new UpdateHandler($store, $api);
        $offset = (int) ($config['polling_offset'] ?? 0);
        foreach ((array) ($result['result'] ?? []) as $update) {
            if (!is_array($update)) continue;
            $handler->handle($update);
            $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
        }
        $handler->maintenance();
        if ($offset !== (int) ($config['polling_offset'] ?? 0)) {
            $config['polling_offset'] = $offset;
            $repo->save($config);
        }
    } catch (Throwable $e) {
        error_log('Bot polling worker: ' . $e->getMessage());
        sleep(5);
    }
}
