#!/usr/bin/env php
<?php
declare(strict_types=1);

use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use SkyGuardian\Config\Paths;
use SkyGuardian\Storage\JsonStore;

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$scope = (string) ($argv[1] ?? '');
if (!in_array($scope, ['news', 'alerts'], true)) { fwrite(STDERR, "Usage: data-channel-worker.php <news|alerts>\n"); exit(2); }

$paths = new Paths($root);
$paths->ensureStorage();
$store = new JsonStore($paths->storage());
$lock = fopen($paths->storage() . '/data-channel-' . $scope . '.lock', 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

$channels = array_values(array_filter($store->read('data_channels'), static fn(array $item): bool =>
    ($item['scope'] ?? null) === $scope && (bool) ($item['enabled'] ?? false)
));
$accounts = [];
foreach ($store->read('telegram_accounts') as $account) {
    if (is_array($account) && isset($account['id'])) $accounts[(string) $account['id']] = $account;
}

$contains = static function (string $text, array $terms): bool {
    foreach ($terms as $term) {
        $term = trim((string) $term);
        if ($term !== '' && mb_stripos($text, $term) !== false) return true;
    }
    return false;
};
$stripLinks = static fn(string $text): string => trim((string) preg_replace('~(?:https?://|www\\.|t\\.me/)[^\\s<>]+~iu', '', $text));
$interval = static function (array $channel): int {
    $value = max(1, (int) ($channel['frequency'] ?? 1));
    return ($channel['frequency_unit'] ?? 'minutes') === 'hours' ? $value * 3600 : $value * 60;
};

foreach ($channels as $channel) {
    $id = (string) ($channel['id'] ?? '');
    if ($id === '') continue;
    $states = $store->read('channel_states');
    $state = is_array($states[$id] ?? null) ? $states[$id] : [];
    $lastCheck = isset($state['last_check']) ? strtotime((string) $state['last_check']) : false;
    if ($lastCheck !== false && time() - $lastCheck < $interval($channel)) continue;

    $store->update('channel_states', static function (array $items) use ($id): array {
        $items[$id] = array_replace($items[$id] ?? [], ['status' => 'checking', 'worker_seen' => gmdate(DATE_ATOM), 'last_error' => null]);
        return $items;
    });

    try {
        $account = $accounts[(string) ($channel['account_id'] ?? '')] ?? null;
        if (!is_array($account) || !(bool) ($account['enabled'] ?? false)) throw new RuntimeException('Telegram account is unavailable or disabled.');
        $sessionPath = (string) ($account['session_path'] ?? '');
        if ($sessionPath === '') throw new RuntimeException('Telegram session path is empty.');
        if (!str_starts_with($sessionPath, '/')) $sessionPath = $root . '/' . ltrim($sessionPath, '/');
        if (!is_dir(dirname($sessionPath)) && !mkdir(dirname($sessionPath), 0770, true) && !is_dir(dirname($sessionPath))) {
            throw new RuntimeException('Cannot create Telegram session directory.');
        }
        $settings = new Settings();
        $settings->setAppInfo((new AppInfo())->setApiId((int) $account['api_id'])->setApiHash((string) $account['api_hash']));
        $settings->getLogger()->setType(Logger::FILE_LOGGER)->setExtra($paths->storage() . '/madeline-' . $scope . '.log');
        $api = new API($sessionPath, $settings);
        $api->start();

        $source = trim((string) ($channel['source'] ?? ''));
        $destination = trim((string) ($channel['destination'] ?? ''));
        if ($source === '' || $destination === '') throw new RuntimeException('Source or destination is empty.');
        $history = (array) $api->messages->getHistory(peer: $source, offset_id: 0, offset_date: 0, add_offset: 0, limit: 50, max_id: 0, min_id: 0, hash: 0);
        $messages = array_values(array_filter((array) ($history['messages'] ?? []), static fn(mixed $m): bool =>
            is_array($m) && ($m['_'] ?? '') === 'message' && (int) ($m['id'] ?? 0) > 0
        ));
        usort($messages, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $latestId = $messages === [] ? 0 : (int) end($messages)['id'];
        $initialized = (bool) ($state['initialized'] ?? false);
        $lastId = max(0, (int) ($state['last_message_id'] ?? 0));
        if (!$initialized) {
            $start = (string) ($channel['fetch_start'] ?? 'new');
            if ($start === 'new') $messages = [];
            else {
                $take = match ($start) { 'last_5' => 5, 'last_10' => 10, 'last_20' => 20, default => 0 };
                $messages = $take > 0 ? array_slice($messages, -$take) : [];
            }
        } else {
            $messages = array_values(array_filter($messages, static fn(array $m): bool => (int) $m['id'] > $lastId));
        }

        $published = 0;
        foreach ($messages as $message) {
            $messageId = (int) $message['id'];
            $text = trim((string) ($message['message'] ?? ''));
            if ($contains($text, (array) ($channel['stop_words'] ?? []))) { $lastId = $messageId; continue; }
            $keywords = (array) ($channel['keywords'] ?? []);
            if ($keywords !== [] && !$contains($text, $keywords)) { $lastId = $messageId; continue; }
            $format = (string) ($channel['format'] ?? 'original');
            if ($format === 'text_without_links') $text = $stripLinks($text);
            $before = trim((string) ($channel['before_text'] ?? ''));
            $after = trim((string) ($channel['after_text'] ?? ''));
            $text = trim(($before !== '' ? $before . "\n\n" : '') . $text . ($after !== '' ? "\n\n" . $after : ''));
            $hasMedia = isset($message['media']) && is_array($message['media']) && ($message['media']['_'] ?? '') !== 'messageMediaEmpty';
            if (in_array($format, ['original', 'media', 'text_and_media'], true) && $hasMedia) {
                $api->messages->sendMedia(peer: $destination, media: $message['media'], message: $format === 'media' ? '' : $text);
            } elseif ($format !== 'media' && $text !== '') {
                $api->messages->sendMessage(peer: $destination, message: $text);
            }
            $published++;
            $lastId = $messageId;
        }

        $store->update('channel_states', static function (array $items) use ($id, $latestId, $lastId, $published): array {
            $old = is_array($items[$id] ?? null) ? $items[$id] : [];
            $items[$id] = array_replace($old, [
                'status' => 'idle', 'initialized' => true, 'last_check' => gmdate(DATE_ATOM),
                'worker_seen' => gmdate(DATE_ATOM), 'last_message_id' => max($lastId, $latestId),
                'last_publish' => $published > 0 ? gmdate(DATE_ATOM) : ($old['last_publish'] ?? null),
                'published_count' => (int) ($old['published_count'] ?? 0) + $published, 'last_error' => null,
            ]);
            return $items;
        });
    } catch (Throwable $e) {
        $store->update('channel_states', static function (array $items) use ($id, $e): array {
            $items[$id] = array_replace($items[$id] ?? [], ['status' => 'error', 'last_check' => gmdate(DATE_ATOM), 'worker_seen' => gmdate(DATE_ATOM), 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
            return $items;
        });
        error_log('Data channel worker [' . $scope . '/' . $id . ']: ' . $e->getMessage());
    }
}

flock($lock, LOCK_UN);
fclose($lock);
