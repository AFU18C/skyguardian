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
            $wholeRegions = collect($alerts)->filter(function (array $alert): bool {
                $uid = (string) ($alert['location_uid'] ?? '');

                return (($alert['location_type'] ?? null) === 'oblast' || $uid === '31')
                    && array_key_exists($uid, GroupChannelBot::ALERT_REGIONS);
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
                    'message' => 'Токен принят. Активных событий уровня областей и Киева: '.$wholeRegions.'.',
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
