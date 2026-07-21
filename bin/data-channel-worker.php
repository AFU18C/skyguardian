#!/usr/bin/env php
<?php
declare(strict_types=1);

use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;

$projectDir = dirname(__DIR__);
$storageDir = $projectDir . '/storage';
$autoload = $projectDir . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing\n");
    exit(1);
}
require_once $autoload;

$lockHandle = fopen($storageDir . '/data-channel-worker.lock', 'c+');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$readJson = static function (string $file): array {
    if (!is_file($file)) return [];
    $handle = fopen($file, 'rb');
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

$writeJson = static function (string $file, array $data) use ($storageDir): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temp = tempnam($storageDir, '.channel-state-');
    if ($temp === false) throw new RuntimeException('Cannot create state temp file');
    try {
        if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Cannot write state');
        chmod($temp, 0600);
        if (!rename($temp, $file)) throw new RuntimeException('Cannot replace state');
        chmod($file, 0600);
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
};

$buildSettings = static function (array $account, string $logFile): Settings {
    $settings = new Settings();
    $settings->setAppInfo(
        (new AppInfo())
            ->setApiId((int) ($account['api_id'] ?? 0))
            ->setApiHash((string) ($account['api_hash'] ?? ''))
    );
    $settings->getLogger()->setType(Logger::FILE_LOGGER)->setExtra($logFile);
    return $settings;
};

$containsAny = static function (string $text, array $terms): bool {
    foreach ($terms as $term) {
        $term = trim((string) $term);
        if ($term !== '' && mb_stripos($text, $term) !== false) return true;
    }
    return false;
};

$stripLinks = static function (string $text): string {
    $text = preg_replace('~https?://\S+|www\.\S+|t\.me/\S+~iu', '', $text) ?? $text;
    return trim(preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text);
};

$customizeText = static function (string $text, array $channel): string {
    if (!(bool) ($channel['custom_text_enabled'] ?? false)) return $text;
    $custom = trim((string) ($channel['custom_text'] ?? ''));
    if ($custom === '') return $text;
    if ((string) ($channel['custom_text_position'] ?? 'after') === 'before') {
        return trim($custom . ($text !== '' ? "\n\n" . $text : ''));
    }
    return trim($text . ($text !== '' ? "\n\n" : '') . $custom);
};

$intervalSeconds = static function (array $channel): int {
    $value = max(1, (int) ($channel['check_frequency'] ?? 60));
    return (string) ($channel['check_frequency_unit'] ?? 'seconds') === 'hours' ? $value * 3600 : $value;
};

$publish = static function (API $api, array $channel, array $message) use ($stripLinks, $customizeText): void {
    $source = (string) $channel['source'];
    $destination = (string) $channel['destination'];
    $id = (int) ($message['id'] ?? 0);
    $format = (string) ($channel['publication_format'] ?? 'original');
    $text = trim((string) ($message['message'] ?? ''));
    $hasMedia = isset($message['media']) && is_array($message['media']) && (($message['media']['_'] ?? '') !== 'messageMediaEmpty');

    if ($format === 'original') {
        $api->messages->forwardMessages(from_peer: $source, id: [$id], to_peer: $destination);
        return;
    }

    if ($format === 'text_without_links') $text = $stripLinks($text);
    $text = $customizeText($text, $channel);

    if ($format === 'media') {
        if ($hasMedia) $api->messages->sendMedia(peer: $destination, media: $message, message: '');
        return;
    }

    if ($format === 'text_and_media' && $hasMedia) {
        $api->messages->sendMedia(peer: $destination, media: $message, message: $text);
        return;
    }

    if ($text !== '') {
        $api->messages->sendMessage(peer: $destination, message: $text);
    }
};

foreach (['news', 'alerts'] as $scope) {
    $channelsFile = $storageDir . '/telegram-' . $scope . '-channels.json';
    $stateFile = $storageDir . '/telegram-' . $scope . '-channel-state.json';
    $accountsFile = $scope === 'news'
        ? $storageDir . '/telegram-news-accounts.json'
        : $storageDir . '/telegram-accounts.json';
    $sessionsDir = $scope === 'news'
        ? $storageDir . '/telegram-news-sessions'
        : $storageDir . '/telegram-sessions';

    $channels = array_values($readJson($channelsFile));
    $accounts = array_values($readJson($accountsFile));
    $states = $readJson($stateFile);
    $accountsById = [];
    foreach ($accounts as $account) {
        if (!is_array($account)) continue;
        $accountsById[(string) ($account['id'] ?? '')] = $account;
    }

    foreach ($channels as $channel) {
        if (!is_array($channel) || !(bool) ($channel['enabled'] ?? true)) continue;
        $id = (string) ($channel['id'] ?? '');
        if ($id === '') continue;

        $state = is_array($states[$id] ?? null) ? $states[$id] : [];
        $lastCheckTimestamp = isset($state['last_check_at']) ? strtotime((string) $state['last_check_at']) : false;
        if ($lastCheckTimestamp !== false && time() - $lastCheckTimestamp < $intervalSeconds($channel)) continue;

        $account = $accountsById[(string) ($channel['account'] ?? '')] ?? null;
        if (!is_array($account) || !(bool) ($account['connected'] ?? false) || !(bool) ($account['enabled'] ?? true)) {
            $states[$id] = array_merge($state, [
                'status' => 'paused',
                'last_check_at' => gmdate(DATE_ATOM),
                'last_error' => 'Технический аккаунт недоступен или выключен.',
            ]);
            $writeJson($stateFile, $states);
            continue;
        }

        try {
            if (!is_dir($sessionsDir)) mkdir($sessionsDir, 0770, true);
            chdir($sessionsDir);
            $api = new API(
                $sessionsDir . '/' . $account['id'] . '.madeline',
                $buildSettings($account, $sessionsDir . '/DataChannelWorker.log')
            );

            $lastId = max(0, (int) ($state['last_message_id'] ?? 0));
            $initialized = (bool) ($state['initialized'] ?? false);
            $history = (array) $api->messages->getHistory(
                peer: (string) $channel['source'],
                limit: 50,
                min_id: $initialized ? $lastId : 0
            );
            $messages = array_values(array_filter((array) ($history['messages'] ?? []), static fn ($message): bool =>
                is_array($message) && ($message['_'] ?? '') === 'message' && (int) ($message['id'] ?? 0) > 0
            ));
            usort($messages, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));

            if (!$initialized) {
                $latestId = $messages ? max(array_map(static fn (array $message): int => (int) $message['id'], $messages)) : 0;
                $start = (string) ($channel['processing_start'] ?? 'new');
                if ($start === 'new') {
                    $states[$id] = array_merge($state, [
                        'initialized' => true,
                        'last_message_id' => $latestId,
                        'status' => 'active',
                        'last_check_at' => gmdate(DATE_ATOM),
                        'last_error' => null,
                    ]);
                    $writeJson($stateFile, $states);
                    continue;
                }
                $take = match ($start) { 'last_5' => 5, 'last_10' => 10, 'last_20' => 20, default => 0 };
                if ($take > 0 && count($messages) > $take) $messages = array_slice($messages, -$take);
                $states[$id] = array_merge($state, ['initialized' => true, 'last_message_id' => 0]);
                $writeJson($stateFile, $states);
            }

            foreach ($messages as $message) {
                $messageId = (int) $message['id'];
                if ($messageId <= (int) ($states[$id]['last_message_id'] ?? 0)) continue;
                $text = trim((string) ($message['message'] ?? ''));
                $keywords = is_array($channel['keywords'] ?? null) ? $channel['keywords'] : [];
                $stopWords = is_array($channel['stop_words'] ?? null) ? $channel['stop_words'] : [];
                $shouldPublish = (!$keywords || $containsAny($text, $keywords)) && !$containsAny($text, $stopWords);

                if ($shouldPublish) {
                    $publish($api, $channel, $message);
                    $states[$id]['last_publish_at'] = gmdate(DATE_ATOM);
                    $states[$id]['published_count'] = (int) ($states[$id]['published_count'] ?? 0) + 1;
                }

                $states[$id]['last_message_id'] = $messageId;
                $states[$id]['status'] = 'active';
                $states[$id]['last_check_at'] = gmdate(DATE_ATOM);
                $states[$id]['last_error'] = null;
                $writeJson($stateFile, $states);
            }

            $states[$id] = array_merge($states[$id] ?? [], [
                'initialized' => true,
                'status' => 'active',
                'last_check_at' => gmdate(DATE_ATOM),
                'last_error' => null,
            ]);
            $writeJson($stateFile, $states);
        } catch (Throwable $exception) {
            $states[$id] = array_merge($state, [
                'status' => 'error',
                'last_check_at' => gmdate(DATE_ATOM),
                'last_error' => mb_substr($exception->getMessage(), 0, 500),
            ]);
            $writeJson($stateFile, $states);
            error_log('Data channel worker [' . $scope . '/' . $id . ']: ' . $exception::class . ': ' . $exception->getMessage());
        }
    }
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
