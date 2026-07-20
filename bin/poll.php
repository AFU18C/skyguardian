<?php

declare(strict_types=1);

use SkyGuardian\Telegram\ChannelPublisher;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$storageFile = $root . '/storage/skyguardian.json';
$lockFile = $root . '/storage/poll.lock';

if (!is_file($storageFile)) {
    fwrite(STDERR, "Storage file not found.\n");
    exit(1);
}

$lock = fopen($lockFile, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

try {
    $data = json_decode((string) file_get_contents($storageFile), true, 512, JSON_THROW_ON_ERROR);
    $now = time();
    $changed = false;

    foreach (['news', 'alerts'] as $section) {
        foreach (($data[$section]['channels'] ?? []) as $index => $channel) {
            if (!(bool) ($channel['active'] ?? false)) {
                continue;
            }

            $seconds = intervalSeconds((int) ($channel['interval_value'] ?? 3), (string) ($channel['interval_unit'] ?? 'seconds'));
            $lastPollAt = (int) ($channel['last_poll_at'] ?? 0);
            if ($lastPollAt > 0 && ($now - $lastPollAt) < $seconds) {
                continue;
            }

            $account = findAccount($data['accounts'] ?? [], (string) ($channel['account_id'] ?? ''));
            if ($account === null || !(bool) ($account['active'] ?? false)) {
                $data[$section]['channels'][$index]['last_error'] = 'Выбранный технический аккаунт отсутствует или отключён.';
                $data[$section]['channels'][$index]['last_poll_at'] = $now;
                $changed = true;
                continue;
            }

            try {
                $publisher = new ChannelPublisher(
                    $root . '/storage/sessions/' . safeId((string) $account['id']) . '.madeline',
                    (int) $account['api_id'],
                    (string) $account['api_hash'],
                );

                if (!$publisher->isConnected()) {
                    throw new RuntimeException('Технический аккаунт не подключён к Telegram.');
                }

                $afterId = (int) ($channel['last_message_id'] ?? 0);
                $messages = $publisher->getNewMessages((string) $channel['source'], $afterId, 20);
                $maxSeenId = $afterId;

                foreach ($messages as $message) {
                    $messageId = (int) ($message['id'] ?? 0);
                    $maxSeenId = max($maxSeenId, $messageId);
                    if (!passesFilters($message, (string) ($channel['keywords'] ?? ''), (string) ($channel['excluded_words'] ?? ''))) {
                        continue;
                    }

                    $publisher->publish(
                        $message,
                        (string) $channel['source'],
                        (string) $channel['destination'],
                        (string) ($channel['publish_mode'] ?? 'forward_original'),
                        (string) ($channel['footer_html'] ?? ''),
                    );
                }

                $data[$section]['channels'][$index]['last_message_id'] = $maxSeenId;
                $data[$section]['channels'][$index]['last_poll_at'] = $now;
                $data[$section]['channels'][$index]['last_success_at'] = $now;
                $data[$section]['channels'][$index]['last_error'] = '';
                $data[$section]['channels'][$index]['published_count'] = (int) ($channel['published_count'] ?? 0) + count($messages);
                $changed = true;
            } catch (Throwable $error) {
                $data[$section]['channels'][$index]['last_poll_at'] = $now;
                $data[$section]['channels'][$index]['last_error'] = $error->getMessage();
                $changed = true;
            }
        }
    }

    if ($changed) {
        $tmp = $storageFile . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
        rename($tmp, $storageFile);
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

function intervalSeconds(int $value, string $unit): int
{
    return match ($unit) {
        'hours' => max(1, $value) * 3600,
        'minutes' => max(1, $value) * 60,
        default => max(3, $value),
    };
}

function findAccount(array $accounts, string $id): ?array
{
    foreach ($accounts as $account) {
        if ((string) ($account['id'] ?? '') === $id) {
            return $account;
        }
    }
    return null;
}

function safeId(string $id): string
{
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?: 'account';
}

function passesFilters(array $message, string $keywords, string $excluded): bool
{
    $text = mb_strtolower((string) ($message['message'] ?? ''));
    $required = splitTerms($keywords);
    $blocked = splitTerms($excluded);

    foreach ($blocked as $term) {
        if ($term !== '' && str_contains($text, mb_strtolower($term))) {
            return false;
        }
    }

    if ($required === []) {
        return true;
    }

    foreach ($required as $term) {
        if ($term !== '' && str_contains($text, mb_strtolower($term))) {
            return true;
        }
    }
    return false;
}

function splitTerms(string $value): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[,\n]+/u', $value) ?: [])));
}
