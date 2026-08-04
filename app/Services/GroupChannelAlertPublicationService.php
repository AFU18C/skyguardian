<?php

namespace App\Services;

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
                ];
            }

            $queued = 0;

            foreach ($current as $key => $item) {
                $state = $states->get($key);

                if ($state) {
                    $state->update($this->statePayload($item, $now));
                    $states->forget($key);

                    continue;
                }

                $lockedBot->alertStates()->create($this->statePayload($item, $now));

                if ($lockedBot->moduleSetting(
                    GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
                    'publish_start',
                    true,
                )) {
                    $queued += $this->queueEvent(
                        $lockedBot,
                        GroupChannelAlertEvent::KIND_START,
                        $item,
                        $item['started_at'] ?? $now,
                    );
                }
            }

            foreach ($states as $state) {
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
            ];
        });

        $sent = $this->deliverPending($bot->fresh());

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

        foreach ($events->groupBy(fn (GroupChannelAlertEvent $event): string => implode('|', [
            $event->kind,
            $event->alert_type,
            $event->event_at->timezone('Europe/Kyiv')->format('Y-m-d H:i'),
            hash('sha256', trim((string) $event->details)),
        ])) as $batch) {
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
                'region_name' => $item['region_name'],
                'alert_type' => $item['alert_type'],
                'details' => $item['details'] ?? null,
                'event_at' => $eventAt,
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
        $hasDetailsVariable = str_contains($template, '{details}');
        $message = strtr($template, [
            '{region}' => $regions,
            '{time}' => $first->event_at->timezone('Europe/Kyiv')->format('H:i'),
            '{threat_type}' => GroupChannelBot::ALERT_TYPES[$first->alert_type] ?? $first->alert_type,
            '{details}' => $details,
        ]);

        if ($first->kind === GroupChannelAlertEvent::KIND_START
            && $details !== ''
            && ! $hasDetailsVariable) {
            $detailsLine = "🎯 {$details}";
            $message = str_contains($message, "\n🕒")
                ? str_replace("\n🕒", "\n{$detailsLine}\n🕒", $message)
                : $message."\n{$detailsLine}";
        }

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
