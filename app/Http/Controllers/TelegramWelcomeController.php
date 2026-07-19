<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use App\Models\NewsBotSetting;
use App\Services\Telegram\WelcomeSettingsStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TelegramWelcomeController extends Controller
{
    public function store(Request $request, WelcomeSettingsStore $store): RedirectResponse
    {
        $data = $request->validate([
            'chat' => ['required', 'string', 'max:255'],
            'bot' => ['required', 'in:news,alerts'],
        ]);

        $token = $this->botToken($data['bot']);
        if (blank($token)) {
            return back()->withErrors(['group' => 'У выбранного бота не сохранён токен Telegram.'])->withInput();
        }

        try {
            $chat = $this->getChat((string) $token, trim($data['chat']));
            foreach ($store->groups() as $existing) {
                if ((string) ($existing['chat_id'] ?? '') === (string) ($chat['id'] ?? '')) {
                    return back()->withErrors(['group' => 'Эта группа или канал уже добавлены.'])->withInput();
                }
            }

            $store->add([
                'chat' => trim($data['chat']),
                'chat_id' => (string) ($chat['id'] ?? ''),
                'title' => (string) ($chat['title'] ?? $chat['username'] ?? $data['chat']),
                'type' => (string) ($chat['type'] ?? 'group'),
                'bot' => $data['bot'],
            ]);
            $this->syncWebhooks($store);
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['group' => $exception->getMessage() ?: 'Не удалось добавить группу или канал.'])->withInput();
        }

        return back()->with('status', 'Группа или канал добавлены.');
    }

    public function update(Request $request, string $group, WelcomeSettingsStore $store): RedirectResponse
    {
        abort_unless($store->find($group), 404);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'forbidden_words' => ['nullable', 'string', 'max:10000'],
            'forbidden_links' => ['nullable', 'string', 'max:10000'],
            'filter_action' => ['required', 'in:delete,delete_warn,mute'],
            'new_member_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'message_limit' => ['required', 'integer', 'min:2', 'max:100'],
            'message_window_seconds' => ['required', 'integer', 'min:5', 'max:3600'],
            'antispam_action' => ['required', 'in:delete,delete_warn,mute'],
            'mute_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
        ]);

        $store->update($group, [
            'enabled' => $request->boolean('enabled'),
            'message' => trim($data['message']),
            'delete_join_messages' => $request->boolean('delete_join_messages'),
            'delete_leave_messages' => $request->boolean('delete_leave_messages'),
            'delete_pinned_messages' => $request->boolean('delete_pinned_messages'),
            'delete_group_changes' => $request->boolean('delete_group_changes'),
            'filter_enabled' => $request->boolean('filter_enabled'),
            'forbidden_words' => trim((string) ($data['forbidden_words'] ?? '')),
            'forbidden_links' => trim((string) ($data['forbidden_links'] ?? '')),
            'filter_action' => $data['filter_action'],
            'antispam_enabled' => $request->boolean('antispam_enabled'),
            'new_member_minutes' => (int) $data['new_member_minutes'],
            'block_links_for_new' => $request->boolean('block_links_for_new'),
            'message_limit' => (int) $data['message_limit'],
            'message_window_seconds' => (int) $data['message_window_seconds'],
            'antispam_action' => $data['antispam_action'],
            'mute_minutes' => (int) $data['mute_minutes'],
        ]);

        try {
            $this->syncWebhooks($store);
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['group' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Настройки группы сохранены.');
    }

    public function destroy(string $group, WelcomeSettingsStore $store): RedirectResponse
    {
        abort_unless($store->delete($group), 404);
        try {
            $this->syncWebhooks($store);
        } catch (Throwable $exception) {
            report($exception);
        }

        return back()->with('status', 'Группа или канал удалены из SkyGuardian.');
    }

    private function getChat(string $token, string $chat): array
    {
        $response = Http::asForm()->timeout(15)->post("https://api.telegram.org/bot{$token}/getChat", ['chat_id' => $chat]);
        if (! $response->successful() || ! $response->json('ok')) {
            throw new RuntimeException((string) ($response->json('description') ?: 'Telegram не нашёл группу. Добавьте бота в группу и проверьте @username/chat_id.'));
        }

        return (array) $response->json('result', []);
    }

    private function syncWebhooks(WelcomeSettingsStore $store): void
    {
        $groups = $store->groups();
        foreach (['news', 'alerts'] as $bot) {
            $token = $this->botToken($bot);
            if (blank($token)) {
                continue;
            }

            $needed = collect($groups)->contains(fn (array $group): bool => ($group['bot'] ?? null) === $bot);
            $method = $needed ? 'setWebhook' : 'deleteWebhook';
            $payload = $needed ? [
                'url' => route('telegram.welcome.webhook', ['bot' => $bot]),
                'secret_token' => $store->secret(),
                'allowed_updates' => json_encode(['message']),
                'drop_pending_updates' => false,
            ] : ['drop_pending_updates' => false];

            $response = Http::asForm()->timeout(15)->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
            if (! $response->successful() || ! $response->json('ok')) {
                throw new RuntimeException((string) ($response->json('description') ?: 'Telegram не принял webhook управления группой.'));
            }
        }
    }

    private function botToken(string $bot): ?string
    {
        return $bot === 'news'
            ? NewsBotSetting::query()->first()?->bot_token
            : AlertBotSetting::query()->first()?->bot_token;
    }
}
