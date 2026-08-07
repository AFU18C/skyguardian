from pathlib import Path

path = Path('app/Services/GroupChannelAlertPublicationService.php')
text = path.read_text()

old = '''            $isRefresh = $card !== null || $startedAt->lessThan($now->subMinute());

            try {
                $response = $this->telegram->request($bot, 'sendMessage', [
                    'chat_id' => $bot->chat_id,
                    'text' => $this->renderActiveCard($bot, $states, $startedAt, $now, $isRefresh),
                    'disable_notification' => (bool) $bot->moduleSetting(
                        GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
                        'disable_notification',
                        false,
                    ),
                ]);
'''
new = '''            $isRefresh = $card !== null || $startedAt->lessThan($now->subMinute());
            $historySince = $card?->created_at?->toImmutable() ?? $now;
            $cardPayload = $this->renderActiveCardPayload(
                $bot,
                $states,
                $startedAt,
                $historySince,
                $now,
                $isRefresh,
            );

            try {
                $response = $this->telegram->request($bot, 'sendMessage', [
                    'chat_id' => $bot->chat_id,
                    ...$cardPayload,
                    'disable_notification' => (bool) $bot->moduleSetting(
                        GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
                        'disable_notification',
                        false,
                    ),
                ]);
'''
assert old in text
text = text.replace(old, new, 1)

marker = '''    /**
     * @param  Collection<int, GroupChannelAlertState>  $states
     */
    private function renderActiveCard(
'''
replacement = '''    /**
     * @param  Collection<int, GroupChannelAlertState>  $states
     * @return array{text: string, entities?: array<int, array{type: string, offset: int, length: int}>}
     */
    private function renderActiveCardPayload(
        GroupChannelBot $bot,
        Collection $states,
        CarbonImmutable $startedAt,
        CarbonImmutable $historySince,
        CarbonImmutable $updatedAt,
        bool $isRefresh,
    ): array {
        $text = $this->renderActiveCard($bot, $states, $startedAt, $updatedAt, $isRefresh);
        /** @var GroupChannelAlertState $first */
        $first = $states->first();
        $oblast = $this->scopeName($first->scope_region_uid, $first->region_name);
        $history = $this->partialClearHistory(
            $bot,
            $first->scope_region_uid,
            $first->alert_type,
            $historySince,
            $oblast,
        );

        if ($history === '') {
            return ['text' => $text];
        }

        $heading = '🔻 Відбій під час цієї тривоги:';
        $section = "\\n\\n{$heading}\\n{$history}";
        $updatedMarker = "\\n🔄";
        $insertAt = strpos($text, $updatedMarker);

        if ($insertAt === false) {
            $before = $text;
            $text .= $section;
        } else {
            $before = substr($text, 0, $insertAt);
            $text = $before.$section.substr($text, $insertAt);
        }

        return [
            'text' => $text,
            'entities' => [[
                'type' => 'spoiler',
                'offset' => $this->telegramUtf16Length($before."\\n\\n{$heading}\\n"),
                'length' => $this->telegramUtf16Length($history),
            ]],
        ];
    }

    private function partialClearHistory(
        GroupChannelBot $bot,
        ?string $scopeRegionUid,
        string $alertType,
        CarbonImmutable $historySince,
        string $oblast,
    ): string {
        return GroupChannelAlertEvent::query()
            ->where('group_channel_bot_id', $bot->id)
            ->where('kind', GroupChannelAlertEvent::KIND_END)
            ->where('scope_region_uid', $scopeRegionUid)
            ->where('alert_type', $alertType)
            ->where('status', GroupChannelAlertEvent::STATUS_SENT)
            ->where('event_at', '>=', $historySince)
            ->orderBy('event_at')
            ->orderBy('id')
            ->get()
            ->map(fn (GroupChannelAlertEvent $event): string => '› '
                .$this->territoryLabel($event->region_name, $oblast)
                .' — '.$event->event_at->timezone('Europe/Kyiv')->format('H:i'))
            ->unique()
            ->values()
            ->implode("\\n");
    }

    private function telegramUtf16Length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * @param  Collection<int, GroupChannelAlertState>  $states
     */
    private function renderActiveCard(
'''
assert marker in text
text = text.replace(marker, replacement, 1)
path.write_text(text)

# Regression test: green all-clear posts remain, while active card accumulates prior clears under Telegram spoiler.
test_path = Path('tests/Feature/GroupChannelAlertPublicationTest.php')
test = test_path.read_text()
insert_before = '''    public function test_unchanged_snapshot_does_not_republish_active_card(): void
'''
new_test = '''    public function test_partial_clear_history_is_spoilered_in_red_card_and_green_clear_posts_remain(): void
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
        $this->assertStringContainsString('🔻 Відбій під час цієї тривоги:', (string) $red['text']);
        $this->assertStringContainsString('› Бучанський район — ', (string) $red['text']);
        $activePart = strstr((string) $red['text'], '🔻 Відбій під час цієї тривоги:', true);
        $this->assertIsString($activePart);
        $this->assertStringNotContainsString('Бучанський район', $activePart);
        $entities = (array) ($red['entities'] ?? []);
        $this->assertCount(1, $entities);
        $this->assertSame('spoiler', $entities[0]['type'] ?? null);
        $this->assertGreaterThan(0, (int) ($entities[0]['offset'] ?? 0));
        $this->assertGreaterThan(0, (int) ($entities[0]['length'] ?? 0));

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
        $this->assertStringContainsString('› Бучанський район — ', (string) $secondRed['text']);
        $this->assertStringContainsString('› Броварський район — ', (string) $secondRed['text']);
        $this->assertStringContainsString('› Бориспільський район — ', strstr((string) $secondRed['text'], '🔻 Відбій під час цієї тривоги:', true) ?: '');
        $this->assertCount(1, (array) ($secondRed['entities'] ?? []));
    }

'''
assert insert_before in test
test = test.replace(insert_before, new_test + insert_before, 1)
test_path.write_text(test)
