from pathlib import Path
import re

# Alert publication service: URL button -> bot deep link, old callback redirects, /start renders private history.
path = Path('app/Services/GroupChannelAlertPublicationService.php')
text = path.read_text()

pattern = re.compile(r'''    public function handleHistoryCallback\(GroupChannelBot \$bot, array \$callback\): bool\n    \{.*?\n    \}\n\n    private function deliverPending''', re.S)
replacement = r'''    public function handleHistoryCallback(GroupChannelBot $bot, array $callback): bool
    {
        $data = (string) ($callback['data'] ?? '');

        if (! str_starts_with($data, 'sg_ah:')) {
            return false;
        }

        $callbackId = $callback['id'] ?? null;
        $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
        [, $scopeRegionUid, $alertType, $cycleTimestamp] = array_pad(
            explode(':', $data, 5),
            4,
            null,
        );
        $chatId = $message['chat']['id'] ?? null;

        if (! is_string($scopeRegionUid)
            || ! array_key_exists($scopeRegionUid, GroupChannelBot::ALERT_REGIONS)
            || ! is_string($alertType)
            || ! array_key_exists($alertType, GroupChannelBot::ALERT_TYPES)
            || ! ctype_digit((string) $cycleTimestamp)
            || (string) $chatId !== (string) $bot->chat_id) {
            $this->answerHistoryCallback(
                $bot,
                $callbackId,
                'Історія недоступна.',
                null,
                true,
            );

            return true;
        }

        $cycleStartedAt = CarbonImmutable::createFromTimestampUTC((int) $cycleTimestamp);
        $historyUntil = is_numeric($message['date'] ?? null)
            ? CarbonImmutable::createFromTimestampUTC((int) $message['date'])->addSecond()
            : CarbonImmutable::now('UTC');
        $url = $this->historyDeepLink(
            $bot,
            $scopeRegionUid,
            $alertType,
            $cycleStartedAt,
            $historyUntil,
        );

        if ($url === null) {
            $this->answerHistoryCallback(
                $bot,
                $callbackId,
                'Не вдалося відкрити історію в боті.',
                null,
                true,
            );

            return true;
        }

        // Backward compatibility for already published callback buttons: do not edit
        // the channel post; redirect only the user who pressed the button.
        $this->answerHistoryCallback($bot, $callbackId, null, $url);

        return true;
    }

    public function handleHistoryStart(GroupChannelBot $bot, array $message): bool
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = $chat['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if (($chat['type'] ?? null) !== 'private'
            || ! is_numeric($chatId)
            || ! preg_match('/^\\/start(?:@[A-Za-z0-9_]+)?\\s+(ah_[A-Za-z0-9_]+)$/', $text, $matches)) {
            return false;
        }

        $history = $this->parseHistoryStartPayload($matches[1]);
        if ($history === null || $history['bot_id'] !== (int) $bot->id) {
            $this->telegram->request($bot, 'sendMessage', [
                'chat_id' => (int) $chatId,
                'text' => 'Посилання на історію тривоги недійсне.',
            ]);

            return true;
        }

        $oblast = GroupChannelBot::ALERT_REGIONS[$history['scope_region_uid']];
        $entries = $this->partialClearHistoryEntries(
            $bot,
            $history['scope_region_uid'],
            $history['alert_type'],
            $history['cycle_started_at'],
            $oblast,
            $history['history_until'],
        );

        $this->sendPrivateHistory(
            $bot,
            (int) $chatId,
            $oblast,
            $history['alert_type'],
            $entries,
        );

        return true;
    }

    private function deliverPending'''
text, n = pattern.subn(replacement, text, count=1)
assert n == 1, f'handleHistoryCallback replacement failed: {n}'

old = '''        return [
            'text' => $text,
            'reply_markup' => $this->historyReplyMarkup(
                (string) $first->scope_region_uid,
                $first->alert_type,
                $cycleStartedAt,
                false,
            ),
        ];'''
new = '''        $replyMarkup = $this->historyReplyMarkup(
            $bot,
            (string) $first->scope_region_uid,
            $first->alert_type,
            $cycleStartedAt,
            $updatedAt,
        );
        $payload = ['text' => $text];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return $payload;'''
assert old in text, 'renderActiveCardPayload return block not found'
text = text.replace(old, new, 1)

pattern = re.compile(r'''    /\*\* @return array\{inline_keyboard: array<int, array<int, array<string, string>>>\} \*/\n    private function historyReplyMarkup\(.*?\n    private function telegramUtf16Length\(string \$value\): int\n    \{\n        return intdiv\(strlen\(mb_convert_encoding\(\$value, 'UTF-16LE', 'UTF-8'\)\), 2\);\n    \}\n''', re.S)
replacement = r'''    /** @return array{inline_keyboard: array<int, array<int, array<string, string>>>}|null */
    private function historyReplyMarkup(
        GroupChannelBot $bot,
        string $scopeRegionUid,
        string $alertType,
        CarbonImmutable $cycleStartedAt,
        CarbonImmutable $historyUntil,
    ): ?array {
        $url = $this->historyDeepLink(
            $bot,
            $scopeRegionUid,
            $alertType,
            $cycleStartedAt,
            $historyUntil,
        );

        if ($url === null) {
            return null;
        }

        return [
            'inline_keyboard' => [[
                [
                    'text' => 'Показати історію ▾',
                    'url' => $url,
                ],
            ]],
        ];
    }

    private function historyDeepLink(
        GroupChannelBot $bot,
        string $scopeRegionUid,
        string $alertType,
        CarbonImmutable $cycleStartedAt,
        CarbonImmutable $historyUntil,
    ): ?string {
        $username = $this->historyBotUsername($bot);
        $typeCode = $this->historyAlertTypeCode($alertType);

        if ($username === null || $typeCode === null) {
            return null;
        }

        $payload = implode('_', [
            'ah',
            $bot->id,
            $scopeRegionUid,
            $typeCode,
            $cycleStartedAt->getTimestamp(),
            $historyUntil->getTimestamp(),
        ]);

        if (strlen($payload) > 64) {
            return null;
        }

        return "https://t.me/{$username}?start={$payload}";
    }

    private function historyBotUsername(GroupChannelBot $bot): ?string
    {
        $username = ltrim(trim((string) $bot->bot_username), '@');

        if ($username !== '' && preg_match('/^[A-Za-z0-9_]+$/', $username)) {
            return $username;
        }

        try {
            $me = $this->telegram->request($bot, 'getMe');
            $username = ltrim(trim((string) ($me['username'] ?? '')), '@');

            if ($username === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $username)) {
                return null;
            }

            GroupChannelBot::query()->whereKey($bot->id)->update(['bot_username' => $username]);
            $bot->bot_username = $username;

            return $username;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function historyAlertTypeCode(string $alertType): ?string
    {
        return match ($alertType) {
            'air_raid' => 'a',
            'artillery_shelling' => 's',
            'urban_fights' => 'u',
            'chemical' => 'c',
            'nuclear' => 'n',
            default => null,
        };
    }

    private function historyAlertTypeFromCode(string $code): ?string
    {
        return match ($code) {
            'a' => 'air_raid',
            's' => 'artillery_shelling',
            'u' => 'urban_fights',
            'c' => 'chemical',
            'n' => 'nuclear',
            default => null,
        };
    }

    /**
     * @return array{
     *     bot_id: int,
     *     scope_region_uid: string,
     *     alert_type: string,
     *     cycle_started_at: CarbonImmutable,
     *     history_until: CarbonImmutable
     * }|null
     */
    private function parseHistoryStartPayload(string $payload): ?array
    {
        $parts = explode('_', $payload);
        if (count($parts) !== 6 || $parts[0] !== 'ah') {
            return null;
        }

        [, $botId, $scopeRegionUid, $typeCode, $cycleTimestamp, $untilTimestamp] = $parts;
        $alertType = $this->historyAlertTypeFromCode($typeCode);

        if (! ctype_digit($botId)
            || ! ctype_digit($scopeRegionUid)
            || ! array_key_exists($scopeRegionUid, GroupChannelBot::ALERT_REGIONS)
            || $alertType === null
            || ! ctype_digit($cycleTimestamp)
            || ! ctype_digit($untilTimestamp)) {
            return null;
        }

        $cycleStartedAt = CarbonImmutable::createFromTimestampUTC((int) $cycleTimestamp);
        $historyUntil = CarbonImmutable::createFromTimestampUTC((int) $untilTimestamp);

        if ($historyUntil->lessThan($cycleStartedAt)) {
            return null;
        }

        return [
            'bot_id' => (int) $botId,
            'scope_region_uid' => $scopeRegionUid,
            'alert_type' => $alertType,
            'cycle_started_at' => $cycleStartedAt,
            'history_until' => $historyUntil,
        ];
    }

    /** @param Collection<int, string> $entries */
    private function sendPrivateHistory(
        GroupChannelBot $bot,
        int $chatId,
        string $oblast,
        string $alertType,
        Collection $entries,
    ): void {
        $threat = GroupChannelBot::ALERT_TYPES[$alertType] ?? $alertType;
        $header = "🔻 ВІДБІЙ ПІД ЧАС ЦІЄЇ ТРИВОГИ\n\n📍 {$oblast}\n⚠️ {$threat}";

        if ($entries->isEmpty()) {
            $this->telegram->request($bot, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => $header."\n\nІсторія відбоїв для цієї картки відсутня.",
            ]);

            return;
        }

        $current = $header;
        $continuation = "🔻 ІСТОРІЯ ВІДБОЇВ — ПРОДОВЖЕННЯ\n\n📍 {$oblast}";

        foreach ($entries as $entry) {
            $candidate = $current."\n\n".$entry;

            if ($this->telegramUtf16Length($candidate) <= 4096) {
                $current = $candidate;

                continue;
            }

            $this->telegram->request($bot, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => $current,
            ]);
            $current = $continuation."\n\n".$entry;
        }

        $this->telegram->request($bot, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $current,
        ]);
    }

    private function answerHistoryCallback(
        GroupChannelBot $bot,
        mixed $callbackId,
        ?string $text = null,
        ?string $url = null,
        bool $showAlert = false,
    ): void {
        if (! is_string($callbackId) || $callbackId === '') {
            return;
        }

        try {
            $payload = ['callback_query_id' => $callbackId];
            if ($text !== null && $text !== '') {
                $payload['text'] = $text;
            }
            if ($url !== null && $url !== '') {
                $payload['url'] = $url;
            }
            if ($showAlert) {
                $payload['show_alert'] = true;
            }
            $this->telegram->request($bot, 'answerCallbackQuery', $payload);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function telegramUtf16Length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }
'''
text, n = pattern.subn(replacement, text, count=1)
assert n == 1, f'history helpers replacement failed: {n}'
path.write_text(text)

# Webhook service: private /start history is handled before group moderation/storage.
path = Path('app/Services/GroupChannelWebhookService.php')
text = path.read_text()
old = '''    private function handleMessage(GroupChannelBot $bot, array $message): void
    {
        $messageId = (string) ($message['message_id'] ?? '');'''
new = '''    private function handleMessage(GroupChannelBot $bot, array $message): void
    {
        if ($this->alertPublications->handleHistoryStart($bot, $message)) {
            return;
        }

        $messageId = (string) ($message['message_id'] ?? '');'''
assert old in text, 'handleMessage marker not found'
path.write_text(text.replace(old, new, 1))

# Webhook controller: route private deep-link /start to the encoded channel-bot config,
# because the private chat id is the user's id and cannot equal the configured channel id.
path = Path('app/Http/Controllers/GroupChannelWebhookController.php')
text = path.read_text()
old = '''        $bot = GroupChannelBot::query()
            ->where('token_fingerprint', $fingerprint)
            ->where('chat_id', (string) $chatId)
            ->where('is_active', true)
            ->first();'''
new = '''        $botQuery = GroupChannelBot::query()
            ->where('token_fingerprint', $fingerprint)
            ->where('webhook_secret', $secret)
            ->where('is_active', true);
        $historyBotId = $this->historyStartBotId($update);

        if ($historyBotId !== null) {
            $botQuery->whereKey($historyBotId);
        } else {
            $botQuery->where('chat_id', (string) $chatId);
        }

        $bot = $botQuery->first();'''
assert old in text, 'webhook bot lookup block not found'
text = text.replace(old, new, 1)
marker = '''    private function chatId(array $update): int|string|null
    {'''
insert = '''    private function historyStartBotId(array $update): ?int
    {
        if (data_get($update, 'message.chat.type') !== 'private') {
            return null;
        }

        $text = trim((string) data_get($update, 'message.text', ''));
        if (! preg_match('/^\\/start(?:@[A-Za-z0-9_]+)?\\s+ah_(\\d+)_/', $text, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function chatId(array $update): int|string|null
    {'''
assert marker in text, 'chatId marker not found'
path.write_text(text.replace(marker, insert, 1))

# Direct alert Telegram client: map button belongs only to the configured group/channel,
# not to private history replies.
path = Path('app/Services/DirectGroupChannelTelegramService.php')
text = path.read_text()
old = '''        if (in_array($method, ['sendMessage', 'editMessageText'], true)) {
            $payload = $this->withConfiguredMapButton($bot, $method, $payload);
        }'''
new = '''        if (in_array($method, ['sendMessage', 'editMessageText'], true)
            && (string) ($payload['chat_id'] ?? '') === (string) $bot->chat_id) {
            $payload = $this->withConfiguredMapButton($bot, $method, $payload);
        }'''
assert old in text, 'direct service request block not found'
path.write_text(text.replace(old, new, 1))

# Existing publication test: card stays compact, history button is a t.me deep link,
# green all-clear posts stay unchanged, and no channel edit is used.
path = Path('tests/Feature/GroupChannelAlertPublicationTest.php')
text = path.read_text()
text = text.replace(
    "            'chat_id' => '-1001234567890',\n            'is_active' => true,",
    "            'chat_id' => '-1001234567890',\n            'bot_username' => 'test_alert_bot',\n            'is_active' => true,",
    1,
)
pattern = re.compile(r'''    public function test_partial_clear_history_is_compact_toggleable_and_green_clear_posts_remain\(\): void\n    \{.*?\n    \}\n\n    public function test_unchanged_snapshot_does_not_republish_active_card\(\): void''', re.S)
replacement = r'''    public function test_partial_clear_history_is_compact_deep_linked_and_green_clear_posts_remain(): void
    {
        $nextMessageId = 900;
        Http::fake(function (Request $request) use (&$nextMessageId) {
            if (str_ends_with($request->url(), '/deleteMessage')) {
                return Http::response(['ok' => true, 'result' => true]);
            }

            return Http::response([
                'ok' => true,
                'result' => ['message_id' => ++$nextMessageId],
            ]);
        });

        $bot = $this->alertBot();
        $service = app(GroupChannelAlertPublicationService::class);
        $service->processSnapshot($bot, []);

        $service->processSnapshot($bot->fresh(), [
            $this->alert(1401, 'Бориспільський район', 'raion', 5101, 'air_raid', 14),
            $this->alert(1402, 'Броварський район', 'raion', 5102, 'air_raid', 14),
            $this->alert(1403, 'Бучанський район', 'raion', 5103, 'air_raid', 14),
        ]);

        $beforePartial = count(Http::recorded());
        $service->processSnapshot($bot->fresh(), [
            $this->alert(1401, 'Бориспільський район', 'raion', 5101, 'air_raid', 14),
            $this->alert(1402, 'Броварський район', 'raion', 5102, 'air_raid', 14),
        ]);

        $partialRequests = collect(Http::recorded())
            ->slice($beforePartial)
            ->map(fn (array $record): Request => $record[0]);
        $green = $partialRequests->first(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'ВІДБІЙ ТРИВОГИ'));
        $red = $partialRequests->first(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'СТАТУС: АКТИВНА'));

        $this->assertNotNull($green);
        $this->assertNotNull($red);
        $this->assertStringContainsString('Бучанський район', (string) $green['text']);
        $this->assertStringContainsString('🔻 Відбій під час цієї тривоги — 1 територія', (string) $red['text']);
        $this->assertStringNotContainsString('Бучанський район', (string) $red['text']);
        $this->assertSame('Показати історію ▾', data_get($red['reply_markup'] ?? [], 'inline_keyboard.0.0.text'));
        $this->assertStringStartsWith(
            'https://t.me/test_alert_bot?start=ah_'.$bot->id.'_14_a_',
            (string) data_get($red['reply_markup'] ?? [], 'inline_keyboard.0.0.url'),
        );
        $this->assertNull(data_get($red['reply_markup'] ?? [], 'inline_keyboard.0.0.callback_data'));
        $this->assertSame(
            GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_TEXT,
            data_get($red['reply_markup'] ?? [], 'inline_keyboard.1.0.text'),
        );
        $this->assertFalse($partialRequests->contains(
            fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'),
        ));

        $beforeSecondPartial = count(Http::recorded());
        $service->processSnapshot($bot->fresh(), [
            $this->alert(1401, 'Бориспільський район', 'raion', 5101, 'air_raid', 14),
        ]);

        $secondRequests = collect(Http::recorded())
            ->slice($beforeSecondPartial)
            ->map(fn (array $record): Request => $record[0]);
        $secondGreen = $secondRequests->first(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'ВІДБІЙ ТРИВОГИ'));
        $secondRed = $secondRequests->first(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'СТАТУС: АКТИВНА'));

        $this->assertNotNull($secondGreen);
        $this->assertStringContainsString('Броварський район', (string) $secondGreen['text']);
        $this->assertNotNull($secondRed);
        $this->assertStringContainsString('🔻 Відбій під час цієї тривоги — 2 території', (string) $secondRed['text']);
        $this->assertStringContainsString('› Бориспільський район — ', (string) $secondRed['text']);
        $this->assertStringNotContainsString('Бучанський район', (string) $secondRed['text']);
        $this->assertStringNotContainsString('Броварський район', (string) $secondRed['text']);
        $this->assertStringStartsWith(
            'https://t.me/test_alert_bot?start=ah_'.$bot->id.'_14_a_',
            (string) data_get($secondRed['reply_markup'] ?? [], 'inline_keyboard.0.0.url'),
        );
    }

    public function test_unchanged_snapshot_does_not_republish_active_card(): void'''
text, n = pattern.subn(replacement, text, count=1)
assert n == 1, f'publication test replacement failed: {n}'
path.write_text(text)

# Direct service test: URL history row survives when map button is disabled.
path = Path('tests/Feature/GroupChannelAlertDirectTelegramTest.php')
text = path.read_text()
text = text.replace(
    'test_direct_alert_service_preserves_history_toggle_when_map_button_is_disabled',
    'test_direct_alert_service_preserves_history_link_when_map_button_is_disabled',
    1,
)
text = text.replace(
    "                        'callback_data' => 'sg_ah:14:air_raid:1786130000:show',",
    "                        'url' => 'https://t.me/test_alert_bot?start=ah_1_14_a_1786130000_1786130300',",
    1,
)
path.write_text(text)

# End-to-end regression: private /start is routed by encoded bot id and sends history only to that user.
path = Path('tests/Feature/GroupChannelAlertHistoryDeepLinkTest.php')
path.write_text(r'''<?php

namespace Tests\Feature;

use App\Models\GroupChannelAlertEvent;
use App\Models\GroupChannelBot;
use App\Services\GroupChannelWebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelAlertHistoryDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_start_deep_link_routes_to_encoded_bot_and_sends_history_only_to_user(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 7001],
        ]));

        $bot = $this->bot('-1001111111111', 'target_alerts');
        $this->bot('-1002222222222', 'other_alerts');
        $cycle = CarbonImmutable::parse('2026-08-07T17:00:00Z');
        $until = CarbonImmutable::parse('2026-08-07T18:00:00Z');

        GroupChannelAlertEvent::query()->create([
            'group_channel_bot_id' => $bot->id,
            'event_key' => 'deep-link-history-1',
            'kind' => GroupChannelAlertEvent::KIND_END,
            'region_uid' => '1403',
            'scope_region_uid' => '14',
            'region_name' => 'Бучанський район',
            'alert_type' => 'air_raid',
            'event_at' => CarbonImmutable::parse('2026-08-07T17:31:00Z'),
            'status' => GroupChannelAlertEvent::STATUS_SENT,
            'sent_at' => CarbonImmutable::parse('2026-08-07T17:31:01Z'),
        ]);

        $payload = implode('_', [
            'ah',
            $bot->id,
            '14',
            'a',
            $cycle->getTimestamp(),
            $until->getTimestamp(),
        ]);
        $response = $this
            ->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson(route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
                'secret' => $bot->webhook_secret,
            ]), [
                'update_id' => 99001,
                'message' => [
                    'message_id' => 41,
                    'date' => $until->getTimestamp(),
                    'from' => [
                        'id' => 777,
                        'is_bot' => false,
                        'first_name' => 'User',
                    ],
                    'chat' => [
                        'id' => 777,
                        'type' => 'private',
                    ],
                    'text' => '/start '.$payload,
                ],
            ]);

        $response->assertOk();
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === '777'
                && str_contains((string) ($request['text'] ?? ''), 'ВІДБІЙ ПІД ЧАС ЦІЄЇ ТРИВОГИ')
                && str_contains((string) ($request['text'] ?? ''), 'Київська область')
                && str_contains((string) ($request['text'] ?? ''), 'Бучанський район');
        });
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));
        Http::assertNotSent(function (Request $request) use ($bot): bool {
            return str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === (string) $bot->chat_id;
        });
    }

    public function test_old_callback_button_redirects_user_to_bot_without_editing_channel_post(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => true,
        ]));

        $bot = $this->bot('-1001111111111', 'target_alerts');
        $cycle = CarbonImmutable::parse('2026-08-07T17:00:00Z');
        $messageDate = CarbonImmutable::parse('2026-08-07T18:00:00Z');

        app(GroupChannelWebhookService::class)->handle($bot, [
            'callback_query' => [
                'id' => 'legacy-history-button',
                'from' => ['id' => 777],
                'data' => 'sg_ah:14:air_raid:'.$cycle->getTimestamp().':show',
                'message' => [
                    'message_id' => 501,
                    'date' => $messageDate->getTimestamp(),
                    'chat' => ['id' => $bot->chat_id],
                    'text' => 'old active card',
                ],
            ],
        ]);

        Http::assertSent(function (Request $request) use ($bot): bool {
            return str_ends_with($request->url(), '/answerCallbackQuery')
                && ($request['callback_query_id'] ?? null) === 'legacy-history-button'
                && str_starts_with(
                    (string) ($request['url'] ?? ''),
                    'https://t.me/test_alert_bot?start=ah_'.$bot->id.'_14_a_',
                );
        });
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));
    }

    private function bot(string $chatId, string $groupName): GroupChannelBot
    {
        $settings = GroupChannelBot::defaultModuleSettings();
        $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS]['enabled'] = true;
        $token = '123456:test-token';
        $secret = str_repeat('a', 48);

        return GroupChannelBot::query()->create([
            'bot_name' => 'Alert Bot',
            'bot_token' => $token,
            'token_fingerprint' => hash('sha256', $token),
            'webhook_secret' => $secret,
            'admin_id' => '100500',
            'group_name' => $groupName,
            'group_link' => 'https://t.me/'.$groupName,
            'chat_type' => 'channel',
            'chat_id' => $chatId,
            'bot_username' => 'test_alert_bot',
            'is_active' => true,
            'module_settings' => $settings,
        ]);
    }
}
''')
