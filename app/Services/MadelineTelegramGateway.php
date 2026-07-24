<?php

namespace App\Services;

use App\Contracts\TelegramGateway;
use App\Models\Source;
use App\Models\TelegramAccount;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\ParseMode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class MadelineTelegramGateway implements TelegramGateway
{
    public function __construct(private readonly TelegramSessionService $sessions)
    {
    }

    public function inspect(
        TelegramAccount $account,
        string $sourceIdentifier,
        string $destinationIdentifier,
    ): array {
        $api = $this->sessions->api($account);
        $sourceInfo = $api->getInfo($sourceIdentifier);
        $destinationInfo = $api->getInfo($destinationIdentifier);

        if (! is_array($sourceInfo) || ! is_array($destinationInfo)) {
            throw new RuntimeException('Telegram не вернул данные указанного канала.');
        }

        $history = $api->messages->getHistory(
            peer: $sourceIdentifier,
            limit: 1,
            floodWaitLimit: 5,
        );

        $this->assertCanPublish($destinationInfo);

        return [
            'source_peer_id' => (string) $api->getId($sourceIdentifier),
            'destination_peer_id' => (string) $api->getId($destinationIdentifier),
            'latest_message_id' => $this->maximumMessageId($history['messages'] ?? []),
        ];
    }

    public function latestMessageId(TelegramAccount $account, string $sourcePeerId): ?int
    {
        $history = $this->sessions->api($account)->messages->getHistory(
            peer: $sourcePeerId,
            limit: 1,
            floodWaitLimit: 5,
        );

        return $this->maximumMessageId($history['messages'] ?? []);
    }

    public function messagesAfter(
        TelegramAccount $account,
        string $sourcePeerId,
        int $lastMessageId,
        int $limit = 1000,
    ): array {
        $api = $this->sessions->api($account);
        $kept = [];
        $offsetId = 0;
        $previousOldest = null;

        for ($page = 0; $page < 200; $page++) {
            $history = $api->messages->getHistory(
                peer: $sourcePeerId,
                offset_id: $offsetId,
                limit: 100,
                min_id: $lastMessageId,
                floodWaitLimit: 5,
            );

            $messages = array_values(array_filter(
                $history['messages'] ?? [],
                fn (mixed $message): bool => is_array($message)
                    && (int) ($message['id'] ?? 0) > $lastMessageId
                    && ($message['_'] ?? null) !== 'messageEmpty',
            ));

            if ($messages === []) {
                break;
            }

            usort($messages, fn (array $left, array $right): int => (int) $right['id'] <=> (int) $left['id']);
            $oldest = (int) end($messages)['id'];

            if ($previousOldest === $oldest) {
                break;
            }

            $previousOldest = $oldest;
            $offsetId = $oldest;
            $kept = array_merge($kept, $messages);

            if (count($kept) > $limit) {
                $kept = array_slice($kept, -$limit);
            }

            if (count($messages) < 100 || $oldest <= $lastMessageId + 1) {
                break;
            }

            if ($page === 199) {
                throw new RuntimeException('Накопилось слишком много сообщений. Проверка будет повторена без изменения последнего ID.');
            }
        }

        usort($kept, fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);

        return $kept;
    }

    public function messagesByIds(
        TelegramAccount $account,
        string $sourcePeerId,
        array $messageIds,
    ): array {
        $messageIds = array_values(array_unique(array_map('intval', $messageIds)));

        if ($messageIds === []) {
            return [];
        }

        $minimum = min($messageIds);
        $maximum = max($messageIds);
        $history = $this->sessions->api($account)->messages->getHistory(
            peer: $sourcePeerId,
            offset_id: $maximum + 1,
            limit: max(20, count($messageIds) + 5),
            max_id: $maximum + 1,
            min_id: max(0, $minimum - 1),
            floodWaitLimit: 5,
        );

        $wanted = array_fill_keys($messageIds, true);
        $messages = array_values(array_filter(
            $history['messages'] ?? [],
            fn (mixed $message): bool => is_array($message)
                && isset($wanted[(int) ($message['id'] ?? 0)]),
        ));

        usort($messages, fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);

        return $messages;
    }

    public function publish(Source $source, array $messages, string $body, bool $html): ?string
    {
        $source->loadMissing('telegramAccount.telegramApp');
        $account = $source->telegramAccount;

        if (! $account) {
            throw new RuntimeException('Технический аккаунт канала не найден.');
        }

        $api = $this->sessions->api($account);
        $destination = $source->publication_peer_id ?: $source->publication_identifier;
        $parseMode = $html ? ParseMode::HTML : ParseMode::TEXT;

        if ($source->publication_format === 'text') {
            if (trim(strip_tags($body)) === '') {
                return null;
            }

            $sent = $api->sendMessage(
                peer: $destination,
                message: $body,
                parseMode: $parseMode,
                noWebpage: true,
            );

            return isset($sent->id) ? (string) $sent->id : null;
        }

        $mediaMessages = array_values(array_filter(
            $messages,
            fn (array $message): bool => is_array($message['media'] ?? null)
                && in_array($message['media']['_'] ?? null, [
                    'messageMediaPhoto',
                    'messageMediaDocument',
                ], true),
        ));

        if ($mediaMessages === []) {
            if (trim(strip_tags($body)) === '') {
                return null;
            }

            $sent = $api->sendMessage(
                peer: $destination,
                message: $body,
                parseMode: $parseMode,
                noWebpage: true,
            );

            return isset($sent->id) ? (string) $sent->id : null;
        }

        $temporaryDirectory = storage_path('app/telegram/tmp/'.Str::uuid());
        File::ensureDirectoryExists($temporaryDirectory, 0700, true);

        try {
            $uploaded = [];

            foreach ($mediaMessages as $index => $message) {
                $path = $api->downloadToDir($message['media'], $temporaryDirectory);
                $file = new LocalFile($path);
                $caption = $index === 0 ? $body : '';

                if (($message['media']['_'] ?? null) === 'messageMediaPhoto') {
                    $media = $api->uploadPhoto(
                        file: $file,
                        peer: $destination,
                        caption: $caption,
                        parseMode: $parseMode,
                    );
                } else {
                    $media = $api->uploadDocument(
                        file: $file,
                        peer: $destination,
                        caption: $caption,
                        parseMode: $parseMode,
                    );
                }

                $uploaded[] = [
                    '_' => 'inputSingleMedia',
                    'media' => $media,
                    'message' => $caption,
                    'parse_mode' => $parseMode,
                ];
            }

            if (count($uploaded) === 1) {
                $result = $api->messages->sendMedia(
                    peer: $destination,
                    media: $uploaded[0]['media'],
                    message: $body,
                    parse_mode: $parseMode,
                    floodWaitLimit: 5,
                );
            } else {
                $result = $api->messages->sendMultiMedia(
                    peer: $destination,
                    multi_media: $uploaded,
                    floodWaitLimit: 5,
                );
            }

            return $this->extractDestinationMessageId($result);
        } finally {
            File::deleteDirectory($temporaryDirectory);
        }
    }

    /**
     * @param array<string, mixed> $destinationInfo
     */
    private function assertCanPublish(array $destinationInfo): void
    {
        $chat = $destinationInfo['Chat'] ?? $destinationInfo['chat'] ?? [];

        if (! is_array($chat)) {
            return;
        }

        $isBroadcast = ($chat['_'] ?? null) === 'channel' && ! ($chat['megagroup'] ?? false);
        $isCreator = (bool) ($chat['creator'] ?? false);
        $adminRights = is_array($chat['admin_rights'] ?? null) ? $chat['admin_rights'] : [];

        if ($isBroadcast && ! $isCreator && ! ($adminRights['post_messages'] ?? false)) {
            throw new RuntimeException('Технический аккаунт не может публиковать в выбранный канал.');
        }

        $bannedRights = is_array($chat['banned_rights'] ?? null) ? $chat['banned_rights'] : [];

        if (! $isCreator && ($bannedRights['send_messages'] ?? false)) {
            throw new RuntimeException('Техническому аккаунту запрещена отправка сообщений в выбранную группу.');
        }
    }

    /**
     * @param mixed $messages
     */
    private function maximumMessageId(mixed $messages): ?int
    {
        if (! is_array($messages)) {
            return null;
        }

        $ids = array_filter(array_map(
            fn (mixed $message): int => is_array($message) ? (int) ($message['id'] ?? 0) : 0,
            $messages,
        ));

        return $ids === [] ? null : max($ids);
    }

    private function extractDestinationMessageId(mixed $value): ?string
    {
        if (is_object($value) && isset($value->id)) {
            return (string) $value->id;
        }

        if (! is_array($value)) {
            return null;
        }

        $ids = [];
        array_walk_recursive($value, function (mixed $item, string|int $key) use (&$ids): void {
            if ($key === 'id' && is_numeric($item)) {
                $ids[] = (int) $item;
            }
        });

        return $ids === [] ? null : (string) max($ids);
    }
}
