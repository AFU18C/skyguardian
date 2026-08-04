<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Services\AlertsInUaClient;
use App\Services\GroupChannelAlertPublicationService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Throwable;

class GroupChannelAlertsApiCheckController extends Controller
{
    public function __invoke(
        GroupChannelBot $groupChannelBot,
        AlertsInUaClient $client,
        GroupChannelAlertPublicationService $publication,
    ): RedirectResponse {
        try {
            if (! $groupChannelBot->alerts_api_token) {
                throw new RuntimeException('Сначала добавьте токен alerts.in.ua через кнопку «Редактировать».');
            }

            $alerts = $client->activeAlerts((string) $groupChannelBot->alerts_api_token);
            $result = $publication->processSnapshot($groupChannelBot->fresh(), $alerts);

            $message = implode(' ', [
                'Активных событий: '.$result['active'].'.',
                'Добавлено в очередь: '.$result['queued'].'.',
                'Отправлено: '.$result['sent'].'.',
                $result['baseline']
                    ? 'Выполнена первичная синхронизация: уже активные тревоги не отправляются.'
                    : '',
            ]);

            return back()->with([
                'toast' => [
                    'type' => 'success',
                    'title' => 'API и публикация работают',
                    'message' => trim($message),
                ],
                'open_group_channel_manage' => $groupChannelBot->id,
                'open_group_channel_module' => GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            ]);
        } catch (Throwable $e) {
            report($e);
            $publication->markFailure($groupChannelBot, $e);

            return back()->with([
                'toast' => [
                    'type' => 'error',
                    'title' => 'Ошибка обработки тревог',
                    'message' => $e->getMessage(),
                ],
                'open_group_channel_manage' => $groupChannelBot->id,
                'open_group_channel_module' => GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            ]);
        }
    }
}
