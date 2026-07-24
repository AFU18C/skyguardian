<?php

namespace Tests\Fakes;

use App\Contracts\TelegramGateway;
use App\Models\Source;
use App\Models\TelegramAccount;
use Throwable;

class FakeTelegramGateway implements TelegramGateway
{
    /** @var array{source_peer_id: string, destination_peer_id: string, latest_message_id: int|null} */
    public array $inspection = [
        'source_peer_id' => '-100100',
        'destination_peer_id' => '-100200',
        'latest_message_id' => 10,
    ];

    public ?int $latestMessageId = 10;

    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public ?Throwable $messagesException = null;

    /** @var list<array{source_id: int, message_ids: list<int>, body: string, html: bool}> */
    public array $published = [];

    public function inspect(
        TelegramAccount $account,
        string $sourceIdentifier,
        string $destinationIdentifier,
    ): array {
        return $this->inspection;
    }

    public function latestMessageId(TelegramAccount $account, string $sourcePeerId): ?int
    {
        return $this->latestMessageId;
    }

    public function messagesAfter(
        TelegramAccount $account,
        string $sourcePeerId,
        int $lastMessageId,
        int $limit = 1000,
    ): array {
        if ($this->messagesException) {
            throw $this->messagesException;
        }

        return $this->messages;
    }

    public function messagesByIds(
        TelegramAccount $account,
        string $sourcePeerId,
        array $messageIds,
    ): array {
        $wanted = array_fill_keys($messageIds, true);

        return array_values(array_filter(
            $this->messages,
            fn (array $message): bool => isset($wanted[(int) $message['id']]),
        ));
    }

    public function publish(Source $source, array $messages, string $body, bool $html): ?string
    {
        $this->published[] = [
            'source_id' => $source->id,
            'message_ids' => array_map(fn (array $message): int => (int) $message['id'], $messages),
            'body' => $body,
            'html' => $html,
        ];

        return '9001';
    }
}
