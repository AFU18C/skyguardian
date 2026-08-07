from pathlib import Path
import re

# 1) Active alert publisher: compact summary + toggle callback.
path = Path('app/Services/GroupChannelAlertPublicationService.php')
text = path.read_text()

marker = '''    private function deliverPending(GroupChannelBot $bot): int
    {'''
insert = r'''    public function handleHistoryCallback(GroupChannelBot $bot, array $callback): bool
    {
        $data = (string) ($callback['data'] ?? '');

        if (! str_starts_with($data, 'sg_ah:')) {
            return false;
        }

        $callbackId = $callback['id'] ?? null;
        $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
        [, $scopeRegionUid, $alertType, $cycleTimestamp, $action] = array_pad(
            explode(':', $data, 5),
            5,
            null,
        );
        $messageId = $message['message_id'] ?? null;
        $chatId = $message['chat']['id'] ?? null;
        $currentText = $message['text'] ?? null;

        if (! is_string($scopeRegionUid)
            || ! array_key_exists($scopeRegionUid, GroupChannelBot::ALERT_REGIONS)
            || ! is_string($alertType)
            || ! array_key_exists($alertType, GroupChannelBot::ALERT_TYPES)
            || ! ctype_digit((string) $cycleTimestamp)
            || ! in_array($action, ['show', 'hide'], true)
            || ! is_numeric($messageId)
            || (string) $chatId !== (string) $bot->chat_id
            || ! is_string($currentText)) {
            $this->answerHistoryCallback($bot, $callbackId, 'Історія недоступна.');

            return true;
        }

        $cycleStartedAt = CarbonImmutable::createFromTimestampUTC((int) $cycleTimestamp);
        $historyUntil = is_numeric($message['date'] ?? null)
            ? CarbonImmutable::createFromTimestampUTC((int) $message['date'])->addSecond()
            : CarbonImmutable::now('UTC');
        $oblast = GroupChannelBot::ALERT_REGIONS[$scopeRegionUid];
        $history = $this->partialClearHistoryEntries(
            $bot,
            $scopeRegionUid,
            $alertType,
            $cycleStartedAt,
            $oblast,
            $historyUntil,
        );

        if ($history->isEmpty()) {
            $this->answerHistoryCallback($bot, $callbackId, 'Історія відбоїв відсутня.');

            return true;
        }

        $expanded = $action === 'show';
        $updatedText = $this->toggleHistorySection($currentText, $history, $expanded);

        if ($updatedText === null) {
            $this->answerHistoryCallback($bot, $callbackId, 'Не вдалося знайти блок історії.');

            return true;
        }

        $this->telegram->request($bot, 'editMessageText', [
            'chat_id' => $bot->chat_id,
            'message_id' => (int) $messageId,
            'text' => $updatedText,
            'reply_markup' => $this->historyReplyMarkup(
                $scopeRegionUid,
                $alertType,
                $cycleStartedAt,
                $expanded,
            ),
        ]);
        $this->answerHistoryCallback($bot, $callbackId);

        return true;
    }

    private function deliverPending(GroupChannelBot $bot): int
    {'''
assert marker in text
text = text.replace(marker, insert, 1)

pattern = re.compile(
    r'''    /\*\*\n     \* @param  Collection<int, GroupChannelAlertState>  \$states\n     \* @return array\{text: string, entities\?: array<int, array\{type: string, offset: int, length: int\}>\}\n     \*/\n    private function renderActiveCardPayload\(.*?\n    private function telegramUtf16Length\(string \$value\): int\n    \{\n        return intdiv\(strlen\(mb_convert_encoding\(\$value, 'UTF-16LE', 'UTF-8'\)\), 2\);\n    \}\n''',
    re.S,
)
replacement = r'''    /**
     * @param  Collection<int, GroupChannelAlertState>  $states
     * @return array{text: string, reply_markup?: array<string, mixed>}
     */
    private function renderActiveCardPayload(
        GroupChannelBot $bot,
        Collection $states,
        CarbonImmutable $startedAt,
        CarbonImmutable $cycleStartedAt,
        CarbonImmutable $updatedAt,
        bool $isRefresh,
    ): array {
        $text = $this->renderActiveCard($bot, $states, $startedAt, $updatedAt, $isRefresh);
        /** @var GroupChannelAlertState $first */
        $first = $states->first();
        $oblast = $this->scopeName($first->scope_region_uid, $first->region_name);
        $history = $this->partialClearHistoryEntries(
            $bot,
            $first->scope_region_uid,
            $first->alert_type,
            $cycleStartedAt,
            $oblast,
        );

        if ($history->isEmpty()) {
            return ['text' => $text];
        }

        $summary = $this->historySummary($history->count());
        $updatedMarker = "\n🔄";
        $insertAt = strpos($text, $updatedMarker);
        $text = $insertAt === false
            ? $this->finishMessage($text."\n\n{$summary}")
            : $this->finishMessage(substr($text, 0, $insertAt)."\n\n{$summary}".substr($text, $insertAt));

        return [
            'text' => $text,
            'reply_markup' => $this->historyReplyMarkup(
                (string) $first->scope_region_uid,
                $first->alert_type,
                $cycleStartedAt,
                false,
            ),
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function partialClearHistoryEntries(
        GroupChannelBot $bot,
        ?string $scopeRegionUid,
        string $alertType,
        CarbonImmutable $cycleStartedAt,
        string $oblast,
        ?CarbonImmutable $until = null,
    ): Collection {
        $query = GroupChannelAlertEvent::query()
            ->where('group_channel_bot_id', $bot->id)
            ->where('kind', GroupChannelAlertEvent::KIND_END)
            ->where('scope_region_uid', $scopeRegionUid)
            ->where('alert_type', $alertType)
            ->where('status', GroupChannelAlertEvent::STATUS_SENT)
            ->where('event_at', '>=', $cycleStartedAt);

        if ($until) {
            $query->where('event_at', '<=', $until);
        }

        return $query
            ->orderBy('event_at')
            ->orderBy('id')
            ->get()
            ->map(fn (GroupChannelAlertEvent $event): string => '› '
                .$this->territoryLabel($event->region_name, $oblast)
                .' — '.$event->event_at->timezone('Europe/Kyiv')->format('H:i'))
            ->unique()
            ->values();
    }

    private function historySummary(int $count): string
    {
        $lastTwo = $count % 100;
        $last = $count % 10;
        $word = $lastTwo >= 11 && $lastTwo <= 14
            ? 'територій'
            : match ($last) {
                1 => 'територія',
                2, 3, 4 => 'території',
                default => 'територій',
            };

        return "🔻 Відбій під час цієї тривоги — {$count} {$word}";
    }

    /** @return array{inline_keyboard: array<int, array<int, array<string, string>>>} */
    private function historyReplyMarkup(
        string $scopeRegionUid,
        string $alertType,
        CarbonImmutable $cycleStartedAt,
        bool $expanded,
    ): array {
        return [
            'inline_keyboard' => [[
                [
                    'text' => $expanded ? 'Сховати історію ▴' : 'Показати історію ▾',
                    'callback_data' => implode(':', [
                        'sg_ah',
                        $scopeRegionUid,
                        $alertType,
                        $cycleStartedAt->getTimestamp(),
                        $expanded ? 'hide' : 'show',
                    ]),
                ],
            ]],
        ];
    }

    /**
     * @param  Collection<int, string>  $history
     */
    private function toggleHistorySection(string $text, Collection $history, bool $expanded): ?string
    {
        $marker = '🔻 Відбій під час цієї тривоги';
        $start = strpos($text, $marker);

        if ($start === false) {
            return null;
        }

        $updatedAt = strpos($text, "\n🔄", $start);
        $before = rtrim(substr($text, 0, $start));
        $after = $updatedAt === false ? '' : substr($text, $updatedAt);

        if (! $expanded) {
            return $this->finishMessage(
                $before."\n\n".$this->historySummary($history->count()).$after,
            );
        }

        $visible = $history->values();
        do {
            $truncated = $visible->count() < $history->count();
            $lines = $visible->implode("\n").($truncated ? "\n› …" : '');
            $candidate = $this->finishMessage(
                $before."\n\n🔻 Відбій під час цієї тривоги:\n{$lines}".$after,
            );

            if ($this->telegramUtf16Length($candidate) <= 4096) {
                return $candidate;
            }

            $visible->pop();
        } while ($visible->isNotEmpty());

        return $this->finishMessage(
            $before."\n\n".$this->historySummary($history->count()).$after,
        );
    }

    private function answerHistoryCallback(
        GroupChannelBot $bot,
        mixed $callbackId,
        ?string $text = null,
    ): void {
        if (! is_string($callbackId) || $callbackId === '') {
            return;
        }

        try {
            $payload = ['callback_query_id' => $callbackId];
            if ($text !== null && $text !== '') {
                $payload['text'] = $text;
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
assert n == 1, n
path.write_text(text)

# 2) Webhook callback router: send alert-history callbacks to the direct alert publisher.
path = Path('app/Services/GroupChannelWebhookService.php')
text = path.read_text()
old = '''class GroupChannelWebhookService
{
    public function __construct(private readonly GroupChannelTelegramService $telegram) {}
'''
new = '''class GroupChannelWebhookService
{
    public function __construct(
        private readonly GroupChannelTelegramService $telegram,
        private readonly GroupChannelAlertPublicationService $alertPublications,
    ) {}
'''
assert old in text
text = text.replace(old, new, 1)
old = '''    private function handleCallback(GroupChannelBot $bot, array $callback): void
    {
        $data = (string) ($callback['data'] ?? '');
        $callbackId = $callback['id'] ?? null;
        $fromId = isset($callback['from']['id']) ? (string) $callback['from']['id'] : null;

        if (! str_starts_with($data, 'sg_verify:')) {
            return;
        }
'''
new = '''    private function handleCallback(GroupChannelBot $bot, array $callback): void
    {
        if ($this->alertPublications->handleHistoryCallback($bot, $callback)) {
            return;
        }

        $data = (string) ($callback['data'] ?? '');
        $callbackId = $callback['id'] ?? null;
        $fromId = isset($callback['from']['id']) ? (string) $callback['from']['id'] : null;

        if (! str_starts_with($data, 'sg_verify:')) {
            return;
        }
'''
assert old in text
path.write_text(text.replace(old, new, 1))

# 3) Direct Telegram service: preserve toggle rows and append configured map button.
path = Path('app/Services/DirectGroupChannelTelegramService.php')
text = path.read_text()
start = text.index('    private function withConfiguredMapButton(')
end = text.rfind('\n}')
new_block = r'''    private function withConfiguredMapButton(
        GroupChannelBot $bot,
        string $method,
        array $payload,
    ): array {
        $keyboard = $this->existingInlineKeyboard($payload['reply_markup'] ?? null);
        $enabled = (bool) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'map_button_enabled',
            true,
        );

        if ($enabled) {
            $buttonText = trim((string) $bot->moduleSetting(
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
                'map_button_text',
                GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_TEXT,
            ));
            $buttonUrl = trim((string) $bot->moduleSetting(
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
                'map_button_url',
                GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_URL,
            ));

            if ($buttonText !== '' && filter_var($buttonUrl, FILTER_VALIDATE_URL) !== false) {
                $keyboard[] = [[
                    'text' => $buttonText,
                    'url' => $buttonUrl,
                ]];
            }
        }

        if ($keyboard !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        } elseif ($method === 'editMessageText') {
            $payload['reply_markup'] = ['inline_keyboard' => []];
        } else {
            unset($payload['reply_markup']);
        }

        return $payload;
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private function existingInlineKeyboard(mixed $replyMarkup): array
    {
        if (is_string($replyMarkup)) {
            $decoded = json_decode($replyMarkup, true);
            $replyMarkup = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($replyMarkup) || ! is_array($replyMarkup['inline_keyboard'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            $replyMarkup['inline_keyboard'],
            fn (mixed $row): bool => is_array($row) && $row !== [],
        ));
    }
'''
text = text[:start] + new_block + text[end:]
path.write_text(text)
