from pathlib import Path

service_path = Path('app/Services/GroupChannelAlertPublicationService.php')
service = service_path.read_text()

old = '''            if ($event->kind === GroupChannelAlertEvent::KIND_END && $aggregateEndEvents) {
                return implode('|', [
                    $event->kind,
                    $event->alert_type,
                    $event->event_at->format('Y-m-d H:i:s'),
                ]);
            }'''
new = '''            if ($event->kind === GroupChannelAlertEvent::KIND_END && $aggregateEndEvents) {
                return implode('|', [
                    $event->kind,
                    $event->scope_region_uid ?: 'unknown-scope',
                    $event->alert_type,
                    $event->event_at->format('Y-m-d H:i:s'),
                ]);
            }'''
assert old in service
service = service.replace(old, new, 1)

old = '''            GroupChannelAlertCard::query()->updateOrCreate(
                [
                    'group_channel_bot_id' => $bot->id,
                    'scope_region_uid' => $first->scope_region_uid,
                    'alert_type' => $first->alert_type,
                ],
                [
                    'snapshot_hash' => $hash,
                    'telegram_message_id' => $messageId,
                    'started_at' => $startedAt,
                    'published_at' => $now,
                ],
            );

            if ($oldMessageId && $oldMessageId !== $messageId) {
                $this->safeDeleteMessage($bot, $oldMessageId);
            }

            $sent++;'''
new = '''            if ($oldMessageId && $oldMessageId !== $messageId
                && ! $this->safeDeleteMessage($bot, $oldMessageId)) {
                if ($messageId) {
                    $this->safeDeleteMessage($bot, $messageId);
                }

                throw new RuntimeException('Не удалось удалить предыдущую активную карточку тревоги.');
            }

            GroupChannelAlertCard::query()->updateOrCreate(
                [
                    'group_channel_bot_id' => $bot->id,
                    'scope_region_uid' => $first->scope_region_uid,
                    'alert_type' => $first->alert_type,
                ],
                [
                    'snapshot_hash' => $hash,
                    'telegram_message_id' => $messageId,
                    'started_at' => $startedAt,
                    'published_at' => $now,
                ],
            );

            $sent++;'''
assert old in service
service = service.replace(old, new, 1)

old = '''        foreach ($cards as $key => $card) {
            if (! isset($changedScopes[$key])) {
                continue;
            }

            if ($card->telegram_message_id) {
                $this->safeDeleteMessage($bot, $card->telegram_message_id);
            }

            $card->delete();
        }'''
new = '''        foreach ($cards as $card) {
            if ($card->telegram_message_id
                && ! $this->safeDeleteMessage($bot, $card->telegram_message_id)) {
                continue;
            }

            $card->delete();
        }'''
assert old in service
service = service.replace(old, new, 1)

old = '''        foreach ($cards as $card) {
            if ($bot->chat_id && $card->telegram_message_id) {
                $this->safeDeleteMessage($bot, $card->telegram_message_id);
            }

            $card->delete();
        }'''
new = '''        foreach ($cards as $card) {
            if ($bot->chat_id && $card->telegram_message_id
                && ! $this->safeDeleteMessage($bot, $card->telegram_message_id)) {
                continue;
            }

            $card->delete();
        }'''
assert old in service
service = service.replace(old, new, 1)

old = '''    private function safeDeleteMessage(GroupChannelBot $bot, int $messageId): void
    {
        try {
            $this->telegram->request($bot, 'deleteMessage', [
                'chat_id' => $bot->chat_id,
                'message_id' => $messageId,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }'''
new = '''    private function safeDeleteMessage(GroupChannelBot $bot, int $messageId): bool
    {
        try {
            $this->telegram->request($bot, 'deleteMessage', [
                'chat_id' => $bot->chat_id,
                'message_id' => $messageId,
            ]);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }'''
assert old in service
service = service.replace(old, new, 1)
service_path.write_text(service)

# Strengthen existing regression assertions without changing the scenario.
test_path = Path('tests/Feature/GroupChannelAlertPublicationTest.php')
test = test_path.read_text()
old = '''            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, 'ВІДБІЙ ТРИВОГИ')
                && str_contains($text, 'м. Харків та тергромада');'''
new = '''            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, 'ВІДБІЙ ТРИВОГИ')
                && str_contains($text, 'Харківська область')
                && str_contains($text, 'СТАТУС: БЕЗПЕЧНО')
                && str_contains($text, 'м. Харків та тергромада')
                && str_contains($text, 'Тривога тривала:');'''
assert old in test
test = test.replace(old, new, 1)
old = '''            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, 'ВІДБІЙ ТРИВОГИ')
                && str_contains($text, 'Купʼянський район')
                && str_contains($text, 'Чугуївський район');'''
new = '''            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, 'ВІДБІЙ ТРИВОГИ')
                && str_contains($text, 'Харківська область')
                && str_contains($text, 'СТАТУС: БЕЗПЕЧНО')
                && str_contains($text, 'Купʼянський район')
                && str_contains($text, 'Чугуївський район')
                && str_contains($text, 'Тривога тривала:');'''
assert old in test
test = test.replace(old, new, 1)
test_path.write_text(test)
