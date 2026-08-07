<?php

namespace App\Services;

use App\Models\GroupChannelAlertCard;
use App\Models\GroupChannelAlertEvent;
use App\Models\GroupChannelAlertState;
use App\Models\GroupChannelBot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class GroupChannelAlertPublicationService
{
    public function __construct(private readonly GroupChannelTelegramService $telegram) {}

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     * @return array{baseline: bool, active: int, queued: int, sent: int}
     */
    public function processSnapshot(GroupChannelBot $bot, array $alerts): array
    {
        if (! $bot->is_active || ! $bot->moduleEnabled(GroupChannelBot::MODULE_ALERT_PUBLICATIONS)) {
            return ['baseline' => false, 'active' => 0, 'queued' => 0, 'sent' => 0];
        }

        $now = CarbonImmutable::now('UTC');
        $current = $this->normalizeAlerts($bot, $alerts, $now);
        $result = DB::transaction(function () use ($bot, $current, $now): array {
            $lockedBot = GroupChannelBot::query()->lockForUpdate()->findOrFail($bot->id);
            $states = $lockedBot->alertStates()
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (GroupChannelAlertState $state): string => $this->stateKey(
                    $state->region_uid,
                    $state->alert_type,
                ));

            if (! $lockedBot->alerts_api_initialized_at) {
                $lockedBot->alertStates()->delete();

                foreach ($current as $item) {
                    $lockedBot->alertStates()->create($this->statePayload($item, $now));
                }

                $lockedBot->update([
                    'alerts_api_initialized_at' => $now,
                    'alerts_api_last_checked_at' => $now,
                    'alerts_api_last_success_at' => $now,
                    'alerts_api_last_error' => null,
                ]);

                return [
                    'baseline' => true,
                    'active' => count($current),
                    'queued' => 0,
                    'changed_scopes' => [],
                ];
            }

            $queued = 0;
            $changedScopes = [];

            foreach ($current as $key => $item) {
                $state = $states->get($key);

                if ($state) {
                    if ($this->stateChanged($state, $item)) {
                        $changedScopes[$this->cardKey($state->scope_region_uid, $state->alert_type)] = true;
                        $changedScopes[$this->cardKey($item['scope_region_uid'], $item['alert_type'])] = true;
                    }

                    $state->update($this->statePayload($item, $now));
                    $states->forget($key);

                    continue;
                }

                $lockedBot->alertStates()->create($this->statePayload($item, $now));
                $changedScopes[$this->cardKey($item['scope_region_uid'], $item['alert_type'])] = true;
            }

            foreach ($states as $state) {
                $changedScopes[$this->cardKey($state->scope_region_uid, $state->alert_type)] = true;

                if ($lockedBot->moduleSetting(
                    GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
                    'publish_end',
                    true,
                )) {
                    $queued += $this->queueEvent(
                        $lockedBot,
                        GroupChannelAlertEvent::KIND_END,
                        [
                            'region_uid' => $state->region_uid,
                            'scope_region_uid' => $state->scope_region_uid,
                            'region_name' => $state->region_name,
                            'alert_type' => $state->alert_type,
                            'details' => $state->details,
                            'source_alert_id' => $state->source_alert_id,
                            'started_at' => $state->started_at?->toImmutable(),
                        ],
                        $now,
                    );
                }

                $state->delete();
            }

            $lockedBot->update([
                'alerts_api_last_checked_at' => $now,
                'alerts_api_last_success_at' => $now,
                'alerts_api_last_error' => null,
            ]);

            return [
                'baseline' => false,
                'active' => count($current),
                'queued' => $queued,
                'changed_scopes' => array_keys($changedScopes),
            ];
        });

        $freshBot = $bot->fresh();
        $sent = $this->deliverPending($freshBot);

        if (! $result['baseline']) {
            if ($freshBot->moduleSetting(
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
                'publish_start',
                true,
            )) {
                $sent += $this->syncActiveCards($freshBot, $now, $result['changed_scopes']);
            } else {
                $this->removeActiveCards($freshBot);
            }
        }

        unset($result['changed_scopes']);

        return [...$result, 'sent' => $sent];
    }

    public function markFailure(GroupChannelBot $bot, Throwable $error): void
    {
        $bot->update([
            'alerts_api_last_checked_at' => now(),
            'alerts_api_last_error' => $error->getMessage(),
        ]);
    }

    public function resetBaseline(GroupChannelBot $bot): void
    {
        $this->removeActiveCards($bot);

        DB::transaction(function () use ($bot): void {
            $lockedBot = GroupChannelBot::query()->lockForUpdate()->findOrFail($bot->id);
            $lockedBot->alertStates()->delete();
            $lockedBot->alertEvents()
                ->whereIn('status', [
                    GroupChannelAlertEvent::STATUS_PENDING,
                    GroupChannelAlertEvent::STATUS_SENDING,
                    GroupChannelAlertEvent::STATUS_ERROR,
                ])
                ->delete();
            $lockedBot->update([
                'alerts_api_initialized_at' => null,
                'alerts_api_last_error' => null,
            ]);
        });
    }

    private function deliverPending(GroupChannelBot $bot): int
    {
        $events = $bot->alertEvents()
            ->where(function ($query): void {
                $query->whereIn('status', [
                    GroupChannelAlertEvent::STATUS_PENDING,
                    GroupChannelAlertEvent::STATUS_ERROR,
                ])->orWhere(function ($query): void {
                    $query->where('status', GroupChannelAlertEvent::STATUS_SENDING)
                        ->where('sending_started_at', '<=', now()->subMinutes(10));
                });
            })
            ->where('attempts', '<', 10)
            ->orderBy('event_at')
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($events->isEmpty()) {
            return 0;
        }

        if (! $bot->chat_id) {
            throw new RuntimeException('Сначала проверьте подключение бота, чтобы определить Chat ID.');
        }

        $sent = 0;
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
                    $event->scope_region_uid ?: 'unknown-scope',
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
        }) as $batch) {
            $ids = $batch->pluck('id')->all();
            $claimed = GroupChannelAlertEvent::query()
                ->whereIn('id', $ids)
                ->where(function ($query): void {
                    $query->whereIn('status', [
                        GroupChannelAlertEvent::STATUS_PENDING,
                        GroupChannelAlertEvent::STATUS_ERROR,
                    ])->orWhere(function ($query): void {
                        $query->where('status', GroupChannelAlertEvent::STATUS_SENDING)
                            ->where('sending_started_at', '<=', now()->subMinutes(10));
                    });
                })
                ->update([
                    'status' => GroupChannelAlertEvent::STATUS_SENDING,
                    'sending_started_at' => now(),
                    'last_error' => null,
                    'attempts' => DB::raw('attempts + 1'),
                ]);

            if ($claimed !== count($ids)) {
                continue;
            }

            try {
                $this->telegram->request($bot, 'sendMessage', [
                    'chat_id' => $bot->chat_id,
                    'text' => $this->renderMessage($bot, $batch),
                    'disable_notification' => (bool) $bot->moduleSetting(
                        GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
                        'disable_notification',
                        false,
                    ),
                ]);

                GroupChannelAlertEvent::query()->whereIn('id', $ids)->update([
                    'status' => GroupChannelAlertEvent::STATUS_SENT,
                    'sending_started_at' => null,
                    'sent_at' => now(),
                    'last_error' => null,
                ]);
                $sent += count($ids);
            } catch (Throwable $e) {
                GroupChannelAlertEvent::query()->whereIn('id', $ids)->update([
                    'status' => GroupChannelAlertEvent::STATUS_ERROR,
                    'sending_started_at' => null,
                    'last_error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        return $sent;
    }

    /**
     * @param  array<int, string>  $changedScopes
     */
    private function syncActiveCards(GroupChannelBot $bot, CarbonImmutable $now, array $changedScopes): int
    {
        if (! $bot->chat_id) {
            throw new RuntimeException('Сначала проверьте подключение бота, чтобы определить Chat ID.');
        }

        $changedScopes = array_fill_keys($changedScopes, true);
        $groups = GroupChannelAlertState::query()
            ->where('group_channel_bot_id', $bot->id)
            ->whereNotNull('scope_region_uid')
            ->orderBy('region_name')
            ->get()
            ->groupBy(fn (GroupChannelAlertState $state): string => $this->cardKey(
                $state->scope_region_uid,
                $state->alert_type,
            ));

        $cards = GroupChannelAlertCard::query()
            ->where('group_channel_bot_id', $bot->id)
            ->get()
            ->keyBy(fn (GroupChannelAlertCard $card): string => $this->cardKey(
                $card->scope_region_uid,
                $card->alert_type,
            ));

        $sent = 0;

        foreach ($groups as $key => $states) {
            /** @var GroupChannelAlertState $first */
            $first = $states->first();
            /** @var GroupChannelAlertCard|null $card */
            $card = $cards->get($key);
            $hash = $this->activeSnapshotHash($states);
            $startedAt = $states
                ->pluck('started_at')
                ->filter()
                ->sortBy(fn (CarbonImmutable $date): int => $date->getTimestamp())
                ->first()?->toImmutable() ?? $now;

            $cards->forget($key);

            if (! $card && ! isset($changedScopes[$key])) {
                GroupChannelAlertCard::query()->create([
                    'group_channel_bot_id' => $bot->id,
                    'scope_region_uid' => $first->scope_region_uid,
                    'alert_type' => $first->alert_type,
                    'snapshot_hash' => $hash,
                    'telegram_message_id' => null,
                    'started_at' => $startedAt,
                    'published_at' => null,
                ]);

                continue;
            }

            if ($card && hash_equals($card->snapshot_hash, $hash)) {
                continue;
            }

            $isRefresh = $card !== null || $startedAt->lessThan($now->subMinute());

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
            } catch (Throwable $e) {
                GroupChannelAlertCard::query()->updateOrCreate(
                    [
                        'group_channel_bot_id' => $bot->id,
                        'scope_region_uid' => $first->scope_region_uid,
                        'alert_type' => $first->alert_type,
                    ],
                    [
                        'snapshot_hash' => hash('sha256', 'retry|'.$hash),
                        'telegram_message_id' => $card?->telegram_message_id,
                        'started_at' => $startedAt,
                        'published_at' => $card?->published_at,
                    ],
                );

                throw $e;
            }

            $messageId = is_array($response) && is_numeric($response['message_id'] ?? null)
                ? (int) $response['message_id']
                : null;
            $oldMessageId = $card?->telegram_message_id;

            if ($oldMessageId && $oldMessageId !== $messageId
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

            $sent++;
        }

        foreach ($cards as $key => $card) {
            if (! isset($changedScopes[$key])) {
                continue;
            }

            // A fully cleared oblast keeps its last red Telegram post as history.
            // Drop only the active-card tracking row so a future alert starts a new card.
            $card->delete();
        }

        return $sent;
    }

    private function removeActiveCards(GroupChannelBot $bot): void
    {
        $cards = GroupChannelAlertCard::query()
            ->where('group_channel_bot_id', $bot->id)
            ->get();

        foreach ($cards as $card) {
            if ($bot->chat_id && $card->telegram_message_id
                && ! $this->safeDeleteMessage($bot, $card->telegram_message_id)) {
                continue;
            }

            $card->delete();
        }
    }

    private function safeDeleteMessage(GroupChannelBot $bot, int $messageId): bool
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
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     * @return array<string, array<string, mixed>>
     */
    private function normalizeAlerts(GroupChannelBot $bot, array $alerts, CarbonImmutable $now): array
    {
        $allUkraine = (bool) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'all_ukraine',
            true,
        );
        $selectedRegions = array_map('strval', (array) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'region_uids',
            array_keys(GroupChannelBot::ALERT_REGIONS),
        ));
        $selectedTypes = array_map('strval', (array) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'alert_types',
            array_keys(GroupChannelBot::ALERT_TYPES),
        ));
        $normalized = [];

        foreach ($alerts as $alert) {
            $locationUid = trim((string) ($alert['location_uid'] ?? ''));
            $locationType = trim((string) ($alert['location_type'] ?? ''));
            $oblastUid = trim((string) ($alert['location_oblast_uid'] ?? ''));
            $alertType = trim((string) ($alert['alert_type'] ?? ''));
            $isSupportedLocation = in_array(
                $locationType,
                ['oblast', 'raion', 'city', 'hromada'],
                true,
            );
            $scopeRegionUid = $locationType === 'oblast'
                ? $locationUid
                : ($oblastUid !== '' ? $oblastUid : $locationUid);

            if (! $isSupportedLocation
                || $locationUid === ''
                || ! array_key_exists($scopeRegionUid, GroupChannelBot::ALERT_REGIONS)
                || ! in_array($alertType, $selectedTypes, true)
                || (! $allUkraine && ! in_array($scopeRegionUid, $selectedRegions, true))) {
                continue;
            }

            $startedAt = $this->date($alert['started_at'] ?? null) ?? $now;
            $item = [
                'region_uid' => $locationUid,
                'scope_region_uid' => $scopeRegionUid,
                'region_name' => $this->locationName($alert, $locationType, $scopeRegionUid),
                'alert_type' => $alertType,
                'details' => $this->details($alert['notes'] ?? null),
                'source_alert_id' => is_numeric($alert['id'] ?? null) ? (int) $alert['id'] : null,
                'started_at' => $startedAt,
            ];
            $key = $this->stateKey($locationUid, $alertType);

            if (! isset($normalized[$key])
                || $startedAt->greaterThan($normalized[$key]['started_at'])) {
                $normalized[$key] = $item;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    private function locationName(array $alert, string $locationType, string $scopeRegionUid): string
    {
        $title = trim((string) ($alert['location_title'] ?? ''));
        $oblast = trim((string) ($alert['location_oblast'] ?? ''));
        $raion = trim((string) ($alert['location_raion'] ?? ''));
        $oblast = $oblast !== ''
            ? $oblast
            : (GroupChannelBot::ALERT_REGIONS[$scopeRegionUid] ?? '');

        if ($locationType === 'oblast') {
            return $title !== '' ? $title : $oblast;
        }

        $parts = [$oblast];

        if ($locationType === 'hromada'
            && $raion !== ''
            && mb_strtolower($raion) !== mb_strtolower($title)) {
            $parts[] = $raion;
        }

        if ($title !== '') {
            $parts[] = $title;
        } elseif ($raion !== '') {
            $parts[] = $raion;
        }

        $parts = collect($parts)
            ->filter()
            ->unique(fn (string $part): string => mb_strtolower($part))
            ->values()
            ->all();

        return $parts !== [] ? implode(' — ', $parts) : 'Невідома локація';
    }

    private function details(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value !== '' ? mb_substr($value, 0, 1500) : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function statePayload(array $item, CarbonImmutable $now): array
    {
        return [
            'region_uid' => $item['region_uid'],
            'scope_region_uid' => $item['scope_region_uid'],
            'region_name' => $item['region_name'],
            'alert_type' => $item['alert_type'],
            'details' => $item['details'],
            'source_alert_id' => $item['source_alert_id'],
            'started_at' => $item['started_at'],
            'last_seen_at' => $now,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function queueEvent(
        GroupChannelBot $bot,
        string $kind,
        array $item,
        CarbonImmutable $eventAt,
    ): int {
        $identity = (string) ($item['source_alert_id']
            ?? ($item['started_at'] instanceof CarbonImmutable
                ? $item['started_at']->toIso8601String()
                : $eventAt->toIso8601String()));
        $eventKey = hash('sha256', implode('|', [
            $kind,
            $item['region_uid'],
            $item['alert_type'],
            $identity,
        ]));

        $event = $bot->alertEvents()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'kind' => $kind,
                'region_uid' => $item['region_uid'],
                'scope_region_uid' => $item['scope_region_uid'] ?? null,
                'region_name' => $item['region_name'],
                'alert_type' => $item['alert_type'],
                'details' => $item['details'] ?? null,
                'event_at' => $eventAt,
                'started_at' => $item['started_at'] ?? null,
                'status' => GroupChannelAlertEvent::STATUS_PENDING,
            ],
        );

        return $event->wasRecentlyCreated ? 1 : 0;
    }

    /**
     * @param  Collection<int, GroupChannelAlertEvent>  $events
     */
    private function renderMessage(GroupChannelBot $bot, Collection $events): string
    {
        /** @var GroupChannelAlertEvent $first */
        $first = $events->first();
        $templateKey = $first->kind === GroupChannelAlertEvent::KIND_START
            ? 'start_template'
            : 'end_template';
        $default = $first->kind === GroupChannelAlertEvent::KIND_START
            ? GroupChannelBot::DEFAULT_ALERT_START_TEMPLATE
            : GroupChannelBot::DEFAULT_ALERT_END_TEMPLATE;
        $template = trim((string) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            $templateKey,
            $default,
        ));

        if ($template === '') {
            $template = $default;
        }

        if ($first->kind === GroupChannelAlertEvent::KIND_START
            && $this->normalizeTemplate($template) === $this->normalizeTemplate(GroupChannelBot::LEGACY_ALERT_START_TEMPLATE)) {
            $template = GroupChannelBot::DEFAULT_ALERT_START_TEMPLATE;
        }

        if ($first->kind === GroupChannelAlertEvent::KIND_END
            && $this->normalizeTemplate($template) === $this->normalizeTemplate(GroupChannelBot::LEGACY_ALERT_END_TEMPLATE)) {
            $template = GroupChannelBot::DEFAULT_ALERT_END_TEMPLATE;
        }

        $regions = $events
            ->pluck('region_name')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->implode("\n📍 ");
        $details = $events
            ->pluck('details')
            ->filter()
            ->unique()
            ->values()
            ->implode("\n🎯 ");
        $oblast = $this->scopeName($first->scope_region_uid, $first->region_name);
        $territories = $events
            ->sortBy(fn (GroupChannelAlertEvent $event): string => $this->territoryLabel($event->region_name, $oblast))
            ->map(fn (GroupChannelAlertEvent $event): string => '› '
                .$this->territoryLabel($event->region_name, $oblast)
                .' — '.$event->event_at->timezone('Europe/Kyiv')->format('H:i'))
            ->unique()
            ->values()
            ->implode("\n");
        $clearBlocks = $first->kind === GroupChannelAlertEvent::KIND_END
            ? $this->renderAllClearBlocks($events)
            : '';
        $hasDetailsVariable = str_contains($template, '{details}');
        $message = strtr($template, [
            '{region}' => $regions,
            '{time}' => $first->event_at->timezone('Europe/Kyiv')->format('H:i'),
            '{threat_type}' => GroupChannelBot::ALERT_TYPES[$first->alert_type] ?? $first->alert_type,
            '{details}' => $details,
            '{headline}' => $this->alertHeadline($first->alert_type),
            '{oblast}' => $oblast,
            '{territories}' => $territories,
            '{clear_blocks}' => $clearBlocks,
            '{updated}' => $first->event_at->timezone('Europe/Kyiv')->format('H:i'),
        ]);

        if ($first->kind === GroupChannelAlertEvent::KIND_START
            && $details !== ''
            && ! $hasDetailsVariable) {
            $detailsLine = "🎯 {$details}";
            $message = str_contains($message, "\n🔄")
                ? str_replace("\n🔄", "\n\n{$detailsLine}\n\n🔄", $message)
                : $message."\n{$detailsLine}";
        }

        return $this->finishMessage($message);
    }

    /**
     * @param  Collection<int, GroupChannelAlertState>  $states
     */
    private function renderActiveCard(
        GroupChannelBot $bot,
        Collection $states,
        CarbonImmutable $startedAt,
        CarbonImmutable $updatedAt,
        bool $isRefresh,
    ): string {
        /** @var GroupChannelAlertState $first */
        $first = $states->first();
        $template = trim((string) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'start_template',
            GroupChannelBot::DEFAULT_ALERT_START_TEMPLATE,
        ));

        if ($template === '') {
            $template = GroupChannelBot::DEFAULT_ALERT_START_TEMPLATE;
        }

        if ($this->normalizeTemplate($template) === $this->normalizeTemplate(GroupChannelBot::LEGACY_ALERT_START_TEMPLATE)) {
            $template = GroupChannelBot::DEFAULT_ALERT_START_TEMPLATE;
        }

        $oblast = $this->scopeName($first->scope_region_uid, $first->region_name);
        $regions = $states
            ->pluck('region_name')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->implode("\n📍 ");
        $territories = $states
            ->sortBy(fn (GroupChannelAlertState $state): string => $this->territoryLabel($state->region_name, $oblast))
            ->map(function (GroupChannelAlertState $state) use ($oblast, $startedAt): string {
                $time = $state->started_at?->timezone('Europe/Kyiv')->format('H:i')
                    ?? $startedAt->timezone('Europe/Kyiv')->format('H:i');

                return '› '.$this->territoryLabel($state->region_name, $oblast).' — '.$time;
            })
            ->unique()
            ->values()
            ->implode("\n");
        $details = $states
            ->pluck('details')
            ->filter()
            ->unique()
            ->values()
            ->implode("\n🎯 ");
        $hasDetailsVariable = str_contains($template, '{details}');
        $hasUpdatedVariable = str_contains($template, '{updated}');
        $message = strtr($template, [
            '{region}' => $regions,
            '{time}' => $startedAt->timezone('Europe/Kyiv')->format('H:i'),
            '{threat_type}' => GroupChannelBot::ALERT_TYPES[$first->alert_type] ?? $first->alert_type,
            '{details}' => $details,
            '{headline}' => $this->alertHeadline($first->alert_type),
            '{oblast}' => $oblast,
            '{territories}' => $territories,
            '{updated}' => $updatedAt->timezone('Europe/Kyiv')->format('H:i'),
        ]);

        if ($details !== '' && ! $hasDetailsVariable) {
            $detailsLine = "🎯 {$details}";
            $message = str_contains($message, "\n🔄")
                ? str_replace("\n🔄", "\n\n{$detailsLine}\n\n🔄", $message)
                : $message."\n{$detailsLine}";
        }

        if ($isRefresh && ! $hasUpdatedVariable) {
            $message .= "\n🔄 Оновлено: ".$updatedAt->timezone('Europe/Kyiv')->format('H:i');
        }

        return $this->finishMessage($message);
    }

    /**
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
                    ->implode("\n");
                $durations = $sorted
                    ->map(fn (GroupChannelAlertEvent $event): string => '› '
                        .$this->territoryLabel($event->region_name, $oblast)
                        .' — '.$this->formatAlertDuration($event->started_at, $event->event_at))
                    ->unique()
                    ->values()
                    ->implode("\n");

                return "📍 {$oblast}\n\n🟢 СТАТУС: БЕЗПЕЧНО\n{$territories}\n\n🕒 Тривога тривала:\n{$durations}";
            })
            ->implode("\n\n");
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
    {
        return mb_strtoupper(GroupChannelBot::ALERT_TYPES[$alertType] ?? $alertType);
    }

    private function scopeName(?string $scopeRegionUid, string $regionName): string
    {
        if ($scopeRegionUid !== null && isset(GroupChannelBot::ALERT_REGIONS[$scopeRegionUid])) {
            return GroupChannelBot::ALERT_REGIONS[$scopeRegionUid];
        }

        return trim(explode(' — ', $regionName)[0] ?? $regionName);
    }

    private function territoryLabel(string $regionName, string $oblast): string
    {
        $regionName = trim($regionName);

        if ($regionName === '' || $regionName === $oblast) {
            return $regionName !== '' ? $regionName : 'Невідома локація';
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(' — ', $regionName)),
            fn (string $part): bool => $part !== '',
        ));

        return $parts !== [] ? (string) end($parts) : $regionName;
    }

    private function normalizeTemplate(string $template): string
    {
        return str_replace(["\r\n", "\r"], "\n", trim($template));
    }

    /**
     * @param  Collection<int, GroupChannelAlertState>  $states
     */
    private function activeSnapshotHash(Collection $states): string
    {
        $payload = $states
            ->sortBy(fn (GroupChannelAlertState $state): string => $state->region_uid.'|'.$state->alert_type)
            ->map(fn (GroupChannelAlertState $state): array => [
                'region_uid' => $state->region_uid,
                'scope_region_uid' => $state->scope_region_uid,
                'region_name' => $state->region_name,
                'alert_type' => $state->alert_type,
                'details' => $state->details,
                'source_alert_id' => $state->source_alert_id,
                'started_at' => $state->started_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function stateChanged(GroupChannelAlertState $state, array $item): bool
    {
        return (string) $state->scope_region_uid !== (string) $item['scope_region_uid']
            || $state->region_name !== $item['region_name']
            || (string) $state->details !== (string) $item['details']
            || $state->source_alert_id !== $item['source_alert_id']
            || $state->started_at?->toIso8601String() !== $item['started_at']->toIso8601String();
    }

    private function finishMessage(string $message): string
    {
        $message = trim(preg_replace('/\n{3,}/', "\n\n", $message) ?? $message);

        if ($message === '') {
            throw new RuntimeException('Шаблон сообщения тревог сформировал пустой текст.');
        }

        return mb_substr($message, 0, 4096);
    }

    private function stateKey(string $regionUid, string $alertType): string
    {
        return $regionUid.'|'.$alertType;
    }

    private function cardKey(?string $scopeRegionUid, string $alertType): string
    {
        return ($scopeRegionUid ?: 'unknown-scope').'|'.$alertType;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
