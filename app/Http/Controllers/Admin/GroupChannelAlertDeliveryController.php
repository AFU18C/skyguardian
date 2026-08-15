<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelAlertCard;
use App\Models\GroupChannelAlertEvent;
use App\Models\GroupChannelAlertState;
use App\Models\GroupChannelBot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroupChannelAlertDeliveryController extends Controller
{
    public function resolveEvent(
        Request $request,
        GroupChannelBot $groupChannelBot,
        GroupChannelAlertEvent $alertEvent,
    ): RedirectResponse {
        abort_unless($alertEvent->group_channel_bot_id === $groupChannelBot->id, 404);
        abort_unless($alertEvent->status === GroupChannelAlertEvent::STATUS_UNCERTAIN, 409);
        $data = $request->validate([
            'resolution' => ['required', Rule::in(['sent', 'retry'])],
        ]);
        $events = GroupChannelAlertEvent::query()
            ->where('group_channel_bot_id', $groupChannelBot->id)
            ->where('status', GroupChannelAlertEvent::STATUS_UNCERTAIN);

        if ($alertEvent->delivery_batch_id) {
            $events->where('delivery_batch_id', $alertEvent->delivery_batch_id);
        } else {
            $events->whereKey($alertEvent->id);
        }

        if ($data['resolution'] === 'sent') {
            $events->update([
                'status' => GroupChannelAlertEvent::STATUS_SENT,
                'delivery_batch_id' => null,
                'sending_started_at' => null,
                'sent_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
        } else {
            $events->update([
                'status' => GroupChannelAlertEvent::STATUS_ERROR,
                'delivery_batch_id' => null,
                'sending_started_at' => null,
                'telegram_message_id' => null,
                'last_error' => 'Повтор разрешён администратором после проверки канала.',
                'updated_at' => now(),
            ]);
        }

        return $this->resolvedResponse($data['resolution']);
    }

    public function resolveCard(
        Request $request,
        GroupChannelBot $groupChannelBot,
        GroupChannelAlertCard $alertCard,
    ): RedirectResponse {
        abort_unless($alertCard->group_channel_bot_id === $groupChannelBot->id, 404);
        abort_unless($alertCard->delivery_status === GroupChannelAlertCard::STATUS_UNCERTAIN, 409);
        $data = $request->validate([
            'resolution' => ['required', Rule::in(['sent', 'retry'])],
            'telegram_message_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $hasActiveState = GroupChannelAlertState::query()
            ->where('group_channel_bot_id', $groupChannelBot->id)
            ->where('scope_region_uid', $alertCard->scope_region_uid)
            ->where('alert_type', $alertCard->alert_type)
            ->exists();

        if ($data['resolution'] === 'sent') {
            $messageId = $data['telegram_message_id'] ?? $alertCard->pending_telegram_message_id;

            if (! $messageId) {
                throw ValidationException::withMessages([
                    'telegram_message_id' => 'Укажите ID опубликованного сообщения Telegram.',
                ]);
            }

            if (! $hasActiveState) {
                $alertCard->delete();
            } else {
                $alertCard->update([
                    'snapshot_hash' => $alertCard->pending_snapshot_hash ?? $alertCard->snapshot_hash,
                    'pending_snapshot_hash' => null,
                    'telegram_message_id' => $messageId,
                    'pending_telegram_message_id' => null,
                    'delivery_status' => GroupChannelAlertCard::STATUS_SENT,
                    'sending_started_at' => null,
                    'published_at' => now(),
                    'last_error' => null,
                ]);
            }
        } elseif (! $hasActiveState) {
            $alertCard->delete();
        } else {
            $retryHash = $alertCard->pending_snapshot_hash ?? $alertCard->snapshot_hash;
            $alertCard->update([
                'snapshot_hash' => hash('sha256', 'retry|'.$retryHash),
                'pending_snapshot_hash' => null,
                'pending_telegram_message_id' => null,
                'delivery_status' => GroupChannelAlertCard::STATUS_ERROR,
                'sending_started_at' => null,
                'last_error' => 'Повтор разрешён администратором после проверки канала.',
            ]);
        }

        return $this->resolvedResponse($data['resolution']);
    }

    private function resolvedResponse(string $resolution): RedirectResponse
    {
        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Неопределённая отправка обработана',
            'message' => $resolution === 'sent'
                ? 'Отправка подтверждена администратором.'
                : 'Повтор разрешён после ручной проверки канала.',
        ]);
    }
}
