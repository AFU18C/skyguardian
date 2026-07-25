<?php

namespace App\Services;

use App\Models\Source;
use RuntimeException;
use Throwable;

class SourceProcessor
{
    public function __construct(
        private readonly TelethonClient $telethon,
        private readonly OperationGate $gate,
        private readonly SourceScheduler $scheduler,
    ) {}

    public function process(Source $source): array
    {
        $account = $source->technicalAccount;

        if (! $account || ! $account->is_active) {
            $this->scheduler->scheduleNext($source);
            throw new RuntimeException('Для источника не назначен активный технический аккаунт.');
        }

        $token = $this->gate->acquire('source.process', $account, 180);

        try {
            $result = $this->telethon->call('fetch_messages', $account, [
                'peer' => $source->source_peer,
                'min_id' => $source->last_message_id,
                'limit' => 100,
            ]);

            $messages = $result['messages'] ?? [];
            $messageIds = array_values(array_map(
                static fn (array $message): int => (int) $message['id'],
                $messages,
            ));

            if ($messageIds && $source->destination_peer) {
                $this->telethon->call('relay_messages', $account, [
                    'source_peer' => $source->source_peer,
                    'destination_peer' => $source->destination_peer,
                    'message_ids' => $messageIds,
                ]);
            }

            $changes = [
                'last_error' => null,
                'last_success_at' => now(),
            ];

            if ($messageIds) {
                $changes['last_message_id'] = max($messageIds);
            }

            $source->forceFill($changes)->save();
            $this->scheduler->scheduleNext($source);

            return [
                'source_id' => $source->id,
                'messages_found' => count($messageIds),
                'messages_relayed' => $source->destination_peer ? count($messageIds) : 0,
                'last_message_id' => $source->fresh()->last_message_id,
            ];
        } catch (Throwable $e) {
            $source->forceFill(['last_error' => $e->getMessage()])->save();
            $this->scheduler->scheduleNext($source);
            throw $e;
        } finally {
            $this->gate->release($token);
        }
    }
}