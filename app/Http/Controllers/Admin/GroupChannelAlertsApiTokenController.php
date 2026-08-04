<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Services\GroupChannelAlertPublicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GroupChannelAlertsApiTokenController extends Controller
{
    public function __invoke(
        Request $request,
        GroupChannelBot $groupChannelBot,
        GroupChannelAlertPublicationService $publications,
    ): RedirectResponse {
        $data = $request->validate([
            'alerts_api_token' => ['nullable', 'string', 'max:512'],
            'remove_alerts_api_token' => ['nullable', 'boolean'],
        ]);
        $token = trim((string) ($data['alerts_api_token'] ?? ''));
        $remove = $request->boolean('remove_alerts_api_token');

        if ($token === '' && ! $remove) {
            return back()->with('toast', [
                'type' => 'warning',
                'title' => 'Без изменений',
                'message' => 'Введите новый токен или отметьте удаление текущего.',
            ]);
        }

        $publications->resetBaseline($groupChannelBot);

        if ($remove && $token === '') {
            $groupChannelBot->update([
                'alerts_api_token' => null,
                'alerts_api_token_fingerprint' => null,
                'alerts_api_last_checked_at' => null,
                'alerts_api_last_success_at' => null,
                'alerts_api_last_error' => null,
            ]);

            return back()->with('toast', [
                'type' => 'success',
                'title' => 'Токен удалён',
                'message' => 'Публикация тревог не сможет работать до добавления нового токена.',
            ]);
        }

        $groupChannelBot->update([
            'alerts_api_token' => $token,
            'alerts_api_token_fingerprint' => hash('sha256', $token),
            'alerts_api_last_checked_at' => null,
            'alerts_api_last_success_at' => null,
            'alerts_api_last_error' => null,
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Токен сохранён',
            'message' => 'Токен alerts.in.ua сохранён в зашифрованном виде.',
        ]);
    }
}
