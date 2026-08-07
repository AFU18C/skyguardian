from pathlib import Path

bot_path = Path('app/Models/GroupChannelBot.php')
bot = bot_path.read_text()
old = '    public const DEFAULT_ALERT_END_TEMPLATE = "✅ ВІДБІЙ ТРИВОГИ\\n\\n📍 {region}\\n🕒 Відбій: {time}";'
new = '    public const LEGACY_ALERT_END_TEMPLATE = "✅ ВІДБІЙ ТРИВОГИ\\n\\n📍 {region}\\n🕒 Відбій: {time}";\n\n    public const DEFAULT_ALERT_END_TEMPLATE = "✅ ВІДБІЙ ТРИВОГИ\\n\\n{clear_blocks}";'
assert old in bot
bot_path.write_text(bot.replace(old, new, 1))

migration_path = Path('database/migrations/2026_08_07_212900_update_default_alert_end_template.php')
migration = migration_path.read_text()
old = '    private const CURRENT = "✅ ВІДБІЙ ТРИВОГИ\\n\\n📍 {oblast}\\n\\n🟢 СТАТУС: БЕЗПЕЧНО\\n{territories}\\n\\n🕒 Тривога тривала:\\n{durations}";'
new = '    private const CURRENT = "✅ ВІДБІЙ ТРИВОГИ\\n\\n{clear_blocks}";'
assert old in migration
migration_path.write_text(migration.replace(old, new, 1))

service_path = Path('app/Services/GroupChannelAlertPublicationService.php')
service = service_path.read_text()

old = '''        $sent = 0;

        foreach ($events->groupBy(fn (GroupChannelAlertEvent $event): string => implode('|', [
            $event->kind,
            $event->scope_region_uid ?: 'unknown-scope',
            $event->alert_type,
            $event->event_at->timezone('Europe/Kyiv')->format('Y-m-d H:i'),
            hash('sha256', trim((string) $event->details)),
        ])) as $batch) {'''
new = '''        $sent = 0;
        $endTemplate = trim((string) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'end_template',
            GroupChannelBot::DEFAULT_ALERT_END_TEMPLATE,
        ));
        $endTemplate = $endTemplate !== '' ? $endTemplate : GroupChannelBot::DEFAULT_ALERT_END_TEMPLATE;
        $aggregateEndEvents = in_array($this->normalizeTemplate($endTemplate), [
            $this->normalizeTemplate(GroupChannelBot::LEGACY_ALERT_END_TEMPLATE),
            $this->normalizeTemplate(GroupChannelBot::DEFAULT_ALERT_END_TEMPLATE),
        ], true);

        foreach ($events->groupBy(function (GroupChannelAlertEvent $event) use ($aggregateEndEvents): string {
            if ($event->kind === GroupChannelAlertEvent::KIND_END && $aggregateEndEvents) {
                return implode('|', [
                    $event->kind,
                    $event->alert_type,
                    $event->event_at->format('Y-m-d H:i:s'),
                ]);
            }

            return implode('|', [
                $event->kind,
                $event->scope_region_uid ?: 'unknown-scope',
                $event->alert_type,
                $event->event_at->timezone('Europe/Kyiv')->format('Y-m-d H:i'),
                hash('sha256', trim((string) $event->details)),
            ]);
        }) as $batch) {'''
assert old in service
service = service.replace(old, new, 1)

old = '''                'details' => $item['details'] ?? null,
                'event_at' => $eventAt,
                'status' => GroupChannelAlertEvent::STATUS_PENDING,'''
new = '''                'details' => $item['details'] ?? null,
                'event_at' => $eventAt,
                'started_at' => $item['started_at'] ?? null,
                'status' => GroupChannelAlertEvent::STATUS_PENDING,'''
assert old in service
service = service.replace(old, new, 1)

old = '''        if ($first->kind === GroupChannelAlertEvent::KIND_START
            && $this->normalizeTemplate($template) === $this->normalizeTemplate(GroupChannelBot::LEGACY_ALERT_START_TEMPLATE)) {
            $template = GroupChannelBot::DEFAULT_ALERT_START_TEMPLATE;
        }

        $regions = $events'''
new = '''        if ($first->kind === GroupChannelAlertEvent::KIND_START
            && $this->normalizeTemplate($template) === $this->normalizeTemplate(GroupChannelBot::LEGACY_ALERT_START_TEMPLATE)) {
            $template = GroupChannelBot::DEFAULT_ALERT_START_TEMPLATE;
        }

        if ($first->kind === GroupChannelAlertEvent::KIND_END
            && $this->normalizeTemplate($template) === $this->normalizeTemplate(GroupChannelBot::LEGACY_ALERT_END_TEMPLATE)) {
            $template = GroupChannelBot::DEFAULT_ALERT_END_TEMPLATE;
        }

        $regions = $events'''
assert old in service
service = service.replace(old, new, 1)

marker = "        $hasDetailsVariable = str_contains($template, '{details}');"
replacement = "        $clearBlocks = $first->kind === GroupChannelAlertEvent::KIND_END\n            ? $this->renderAllClearBlocks($events)\n            : '';\n        $hasDetailsVariable = str_contains($template, '{details}');"
assert marker in service
service = service.replace(marker, replacement, 1)

old = '''            '{territories}' => $territories,
            '{updated}' => $first->event_at->timezone('Europe/Kyiv')->format('H:i'),'''
new = '''            '{territories}' => $territories,
            '{clear_blocks}' => $clearBlocks,
            '{updated}' => $first->event_at->timezone('Europe/Kyiv')->format('H:i'),'''
assert old in service
service = service.replace(old, new, 1)

marker = '''    private function alertHeadline(string $alertType): string
    {'''
helper = '''    /**
     * @param  Collection<int, GroupChannelAlertEvent>  $events
     */
    private function renderAllClearBlocks(Collection $events): string
    {
        return $events
            ->groupBy(fn (GroupChannelAlertEvent $event): string => $event->scope_region_uid ?: 'unknown-scope')
            ->sortBy(function (Collection $scopeEvents): string {
                /** @var GroupChannelAlertEvent $first */
                $first = $scopeEvents->first();

                return $this->scopeName($first->scope_region_uid, $first->region_name);
            })
            ->map(function (Collection $scopeEvents): string {
                /** @var GroupChannelAlertEvent $first */
                $first = $scopeEvents->first();
                $oblast = $this->scopeName($first->scope_region_uid, $first->region_name);
                $sorted = $scopeEvents->sortBy(
                    fn (GroupChannelAlertEvent $event): string => $this->territoryLabel($event->region_name, $oblast),
                );
                $territories = $sorted
                    ->map(fn (GroupChannelAlertEvent $event): string => '› '
                        .$this->territoryLabel($event->region_name, $oblast)
                        .' — '.$event->event_at->timezone('Europe/Kyiv')->format('H:i'))
                    ->unique()
                    ->values()
                    ->implode("\\n");
                $durations = $sorted
                    ->map(fn (GroupChannelAlertEvent $event): string => '› '
                        .$this->territoryLabel($event->region_name, $oblast)
                        .' — '.$this->formatAlertDuration($event->started_at, $event->event_at))
                    ->unique()
                    ->values()
                    ->implode("\\n");

                return "📍 {$oblast}\\n\\n🟢 СТАТУС: БЕЗПЕЧНО\\n{$territories}\\n\\n🕒 Тривога тривала:\\n{$durations}";
            })
            ->implode("\\n\\n");
    }

    private function formatAlertDuration(?CarbonImmutable $startedAt, CarbonImmutable $endedAt): string
    {
        if (! $startedAt) {
            return 'невідомо';
        }

        $totalMinutes = max(0, (int) floor($startedAt->diffInMinutes($endedAt)));
        $days = intdiv($totalMinutes, 1440);
        $hours = intdiv($totalMinutes % 1440, 60);
        $minutes = $totalMinutes % 60;
        $parts = [];

        if ($days > 0) {
            $parts[] = $days.' д';
        }

        if ($hours > 0) {
            $parts[] = $hours.' год';
        }

        if ($minutes > 0 || $parts === []) {
            $parts[] = $minutes.' хв';
        }

        return implode(' ', $parts);
    }

    private function alertHeadline(string $alertType): string
    {'''
assert marker in service
service = service.replace(marker, helper, 1)
service_path.write_text(service)
