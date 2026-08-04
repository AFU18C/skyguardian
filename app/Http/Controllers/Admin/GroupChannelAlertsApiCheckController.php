<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Services\AlertsInUaClient;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Throwable;

class GroupChannelAlertsApiCheckController extends Controller
{
    public function __invoke(
        GroupChannelBot $groupChannelBot,
        AlertsInUaClient $client,
    ): RedirectResponse {
        try {
            if (! $groupChannelBot->alerts_api_token) {
                throw new RuntimeException('Сначала добавьте токен alerts.in.ua через кнопку «Редактировать».');
            }

            $alerts = $client->activeAlerts((string) $groupChannelBot->alerts_api_token);
            $supportedRegions = collect($alerts)->filter(function (array $alert): bool {
                $locationType = trim((string) ($alert['location_type'] ?? ''));
                $locationUid = trim((string) ($alert['location_uid'] ?? ''));
                $oblastUid = trim((string) ($alert['location_oblast_uid'] ?? ''));
                $scopeRegionUid = $locationType === 'oblast'
                    ? $locationUid
                    : ($oblastUid !== '' ? $oblastUid : $locationUid);

                return in_array(
                    $locationType,
                    ['oblast', 'raion', 'city', 'hromada'],
                    true,
                ) && array_key_exists($scopeRegionUid, GroupChannelBot::ALERT_REGIONS);
            })->count();

            $groupChannelBot->update([
                'alerts_api_last_checked_at' => now(),
                'alerts_api_last_success_at' => now(),
                'alerts_api_last_error' => null,
            ]);

            return back()->with([
                'toast' => [
                    'type' => 'success',
                    'title' => 'API работает',
                    'message' => 'Токен принят. Активных событий по областям, районам, городам и громадам: '.$supportedRegions.'.',
                ],
                'open_group_channel_manage' => $groupChannelBot->id,
                'open_group_channel_module' => GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            ]);
        } catch (Throwable $e) {
            report($e);
            $groupChannelBot->update([
                'alerts_api_last_checked_at' => now(),
                'alerts_api_last_error' => $e->getMessage(),
            ]);

            return back()->with([
                'toast' => [
                    'type' => 'error',
                    'title' => 'API недоступен',
                    'message' => $e->getMessage(),
                ],
                'open_group_channel_manage' => $groupChannelBot->id,
                'open_group_channel_module' => GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            ]);
        }
    }
}
