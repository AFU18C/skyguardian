<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Services\GroupChannelWebhookRegistrationService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class GroupChannelWebhookRegistrationController extends Controller
{
    public function __invoke(
        GroupChannelBot $groupChannelBot,
        GroupChannelWebhookRegistrationService $webhook,
    ): RedirectResponse {
        try {
            $webhook->register($groupChannelBot);

            return back()->with([
                'toast' => [
                    'type' => 'success',
                    'title' => 'Webhook включён',
                    'message' => 'Бот принимает новые сообщения и публикации для всех добавленных групп и каналов.',
                ],
                'open_group_channel_manage' => $groupChannelBot->id,
            ]);
        } catch (Throwable $e) {
            report($e);
            $groupChannelBot->update(['webhook_last_error' => $e->getMessage()]);

            return back()->with([
                'toast' => [
                    'type' => 'error',
                    'title' => 'Ошибка webhook',
                    'message' => $e->getMessage(),
                ],
                'open_group_channel_manage' => $groupChannelBot->id,
            ]);
        }
    }
}
