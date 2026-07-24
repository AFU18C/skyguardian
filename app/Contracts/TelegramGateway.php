<?php

namespace App\Contracts;

use App\Models\Source;
use App\Models\TelegramAccount;

interface TelegramGateway
{
    /**
     * @return array{source_peer_id: string, destination_peer_id: string, latest_message_id: int|null}
     */
    public function inspect(
        TelegramAccount $account,
        string $sourceIdentifier,
        string $destinationIdentifier,
    ): array;

    public function latestMessageId(TelegramAccount $account, string $sourcePeerId): ?int;

    /**
     * @return list<array<string, mixed>>
     */
    public function messagesAfter(
        TelegramAccount $account,
        string $sourcePeerId,
        int $lastMessageId,
        int $limit = 1000,
    ): array;

    /**
     * @param list<int> $messageIds
     * @return list<array<string, mixed>>
     */
    public function messagesByIds(
        TelegramAccount $account,
        string $sourcePeerId,
        array $messageIds,
    ): array;

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function publish(Source $source, array $messages, string $body, bool $html): ?string;
}
