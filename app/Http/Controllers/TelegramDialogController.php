<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\TelegramAccount;
use App\Services\TelegramSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TelegramDialogController extends Controller
{
    public function __construct(private readonly TelegramSessionService $telegramSessions)
    {
    }

    public function index(TelegramAccount $telegramAccount): View|RedirectResponse
    {
        abort_unless($telegramAccount->status === 'connected', 422, 'Telegram-аккаунт не подключён.');

        try {
            $dialogs = collect($this->telegramSessions->api($telegramAccount)->getFullDialogs())
                ->map(function (array $dialog, string|int $peerId): array {
                    $type = $dialog['type'] ?? null;
                    $name = $dialog['name'] ?? $dialog['title'] ?? $dialog['username'] ?? (string) $peerId;

                    return [
                        'peer_id' => (string) $peerId,
                        'name' => (string) $name,
                        'username' => $dialog['username'] ?? null,
                        'type' => $type,
                    ];
                })
                ->filter(fn (array $dialog): bool => in_array($dialog['type'], ['channel', 'supergroup'], true))
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values();

            $selectedPeerIds = Source::query()
                ->where('telegram_account_id', $telegramAccount->id)
                ->whereNotNull('peer_id')
                ->pluck('peer_id')
                ->all();

            return view('integrations.telegram-dialogs', compact('telegramAccount', 'dialogs', 'selectedPeerIds'));
        } catch (\Throwable $exception) {
            $this->telegramSessions->markError($telegramAccount, $exception);

            return redirect()->route('integrations.index')->withErrors(['telegram' => $exception->getMessage()]);
        }
    }

    public function store(Request $request, TelegramAccount $telegramAccount): RedirectResponse
    {
        abort_unless($telegramAccount->status === 'connected', 422);

        $data = $request->validate([
            'peer_id' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
        ]);

        Source::query()->updateOrCreate(
            ['telegram_account_id' => $telegramAccount->id, 'peer_id' => $data['peer_id']],
            [
                'name' => $data['name'],
                'type' => 'telegram',
                'identifier' => filled($data['username']) ? '@'.ltrim($data['username'], '@') : $data['peer_id'],
                'is_active' => true,
            ],
        );

        return back()->with('status', 'Канал добавлен в источники.');
    }

    public function messages(Request $request, TelegramAccount $telegramAccount): View|RedirectResponse
    {
        abort_unless($telegramAccount->status === 'connected', 422);

        $data = $request->validate([
            'peer_id' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $history = $this->telegramSessions->api($telegramAccount)->messages->getHistory([
                'peer' => $data['peer_id'],
                'offset_id' => 0,
                'offset_date' => 0,
                'add_offset' => 0,
                'limit' => 10,
                'max_id' => 0,
                'min_id' => 0,
                'hash' => 0,
            ]);

            $messages = collect($history['messages'] ?? [])
                ->filter(fn (array $message): bool => ($message['_'] ?? null) === 'message')
                ->map(fn (array $message): array => [
                    'id' => $message['id'] ?? null,
                    'text' => trim((string) ($message['message'] ?? '')),
                    'date' => isset($message['date']) ? date('d.m.Y H:i', (int) $message['date']) : null,
                    'has_media' => isset($message['media']),
                ])
                ->values();

            return view('integrations.telegram-messages', [
                'telegramAccount' => $telegramAccount,
                'channelName' => $data['name'],
                'messages' => $messages,
            ]);
        } catch (\Throwable $exception) {
            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }
    }
}
