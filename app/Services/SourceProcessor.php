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
        $source->loadMissing(['technicalAccount.telegramApi', 'rules']);
        $account = $source->technicalAccount;

        if (! $account || ! $account->is_active) {
            $this->scheduler->scheduleNext($source);
            throw new RuntimeException('Для источника не назначен активный технический аккаунт.');
        }

        $token = $this->gate->acquire('source.process', $account, 180);

        try {
            if ($source->last_message_id === null) {
                $result = $this->telethon->call('latest_message_id', $account, [
                    'peer' => $source->source_peer,
                ]);

                $source->forceFill([
                    'last_message_id' => max((int) ($result['latest_message_id'] ?? 0), 0),
                    'last_error' => null,
                    'last_success_at' => now(),
                ])->save();
                $this->scheduler->scheduleNext($source);

                return [
                    'source_id' => $source->id,
                    'initialized' => true,
                    'messages_found' => 0,
                    'messages_copied' => 0,
                    'last_message_id' => $source->fresh()->last_message_id,
                ];
            }

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
            $copiedCount = 0;
            $failedCount = 0;
            $copyError = null;
            $lastProcessedId = $messageIds ? max($messageIds) : null;

            if ($messageIds && $source->destination_peer) {
                $copyResult = $this->telethon->call('copy_messages', $account, [
                    'source_peer' => $source->source_peer,
                    'destination_peer' => $source->destination_peer,
                    'message_ids' => $messageIds,
                    'settings' => $this->copySettings($source),
                ]);
                $copiedCount = (int) ($copyResult['copied_count'] ?? count($messageIds));
                $failedCount = (int) ($copyResult['failed_count'] ?? 0);
                if ($failedCount > 0) {
                    $lastProcessedId = data_get($copyResult, 'last_processed_id');
                    $firstError = (string) data_get($copyResult, 'failed.0.error', 'Неизвестная ошибка Telegram.');
                    $copyError = "Не скопировано сообщений: {$failedCount}. {$firstError}";
                }
            }

            $changes = [
                'last_error' => $copyError,
                'last_success_at' => now(),
            ];

            if ($lastProcessedId !== null) {
                $changes['last_message_id'] = max((int) $lastProcessedId, (int) $source->last_message_id);
            }

            $source->forceFill($changes)->save();
            $this->scheduler->scheduleNext($source);

            return [
                'source_id' => $source->id,
                'initialized' => false,
                'messages_found' => count($messageIds),
                'messages_copied' => $copiedCount,
                'messages_failed' => $failedCount,
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

    private function copySettings(Source $source): array
    {
        $removePhrases = preg_split('/\R/u', (string) $this->ruleValue($source, 'remove_phrases', '')) ?: [];

        return [
            'copy_mode' => (string) $this->ruleValue($source, 'copy_mode', 'original'),
            'strip_links' => (bool) $this->ruleValue($source, 'strip_links', false),
            'strip_hashtags' => (bool) $this->ruleValue($source, 'strip_hashtags', false),
            'strip_mentions' => (bool) $this->ruleValue($source, 'strip_mentions', false),
            'remove_phrases' => array_values(array_filter(array_map('trim', $removePhrases))),
            'footer_html' => (string) $this->ruleValue($source, 'footer_html', ''),
        ];
    }

    private function ruleValue(Source $source, string $key, mixed $default): mixed
    {
        $rule = $source->rules->firstWhere('key', $key);

        if (! $rule || ! $rule->is_active) {
            return $default;
        }

        return data_get($rule->value, 'value', $default);
    }
}
