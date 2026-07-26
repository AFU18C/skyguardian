<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Models\GroupChannelJoinRequest;
use App\Services\GroupChannelTelegramService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class GroupChannelJoinRequestController extends Controller
{
    public function __construct(private readonly GroupChannelTelegramService $telegram) {}

    public function approve(
        GroupChannelBot $groupChannelBot,
        GroupChannelJoinRequest $joinRequest,
    ): RedirectResponse {
        return $this->act($groupChannelBot, $joinRequest, true);
    }

    public function decline(
        GroupChannelBot $groupChannelBot,
        GroupChannelJoinRequest $joinRequest,
    ): RedirectResponse {
        return $this->act($groupChannelBot, $joinRequest, false);
    }

    private function act(
        GroupChannelBot $bot,
        GroupChannelJoinRequest $joinRequest,
        bool $approve,
    ): RedirectResponse {
        abort_unless($joinRequest->group_channel_bot_id === $bot->id, 404);
        abort_unless($bot->moduleEnabled('join_requests'), 403);

        try {
            $this->telegram->request($bot, $approve ? 'approveChatJoinRequest' : 'declineChatJoinRequest', [
                'chat_id' => $bot->chat_id,
                'user_id' => $joinRequest->telegram_user_id,
            ]);
            $joinRequest->update([
                'status' => $approve
                    ? GroupChannelJoinRequest::STATUS_APPROVED
                    : GroupChannelJoinRequest::STATUS_DECLINED,
                'actioned_at' => now(),
                'last_error' => null,
            ]);

            return back()->with('toast', [
                'type' => 'success',
                'title' => $approve ? 'Заявка одобрена' : 'Заявка отклонена',
                'message' => 'Решение отправлено в Telegram.',
            ]);
        } catch (Throwable $e) {
            report($e);
            $joinRequest->update(['last_error' => $e->getMessage()]);

            return back()->with('toast', [
                'type' => 'error',
                'title' => 'Не удалось обработать заявку',
                'message' => $e->getMessage(),
            ]);
        }
    }
}
