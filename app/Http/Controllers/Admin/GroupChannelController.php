<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class GroupChannelController extends Controller
{
    public function index(): View
    {
        return view('admin.group-channel', [
            'bots' => GroupChannelBot::query()->latest()->paginate(12),
            'availableModules' => GroupChannelBot::MODULES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['module_settings'] = $this->disabledModules();
        GroupChannelBot::query()->create($data);

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Добавлено',
            'message' => 'Бот и группа/канал сохранены. Все функции отключены.',
        ]);
    }

    public function update(Request $request, GroupChannelBot $groupChannelBot): RedirectResponse
    {
        $data = $this->validated($request, $groupChannelBot);

        if (empty($data['bot_token'])) {
            unset($data['bot_token']);
        }

        $groupChannelBot->update($data);

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Сохранено',
            'message' => 'Настройки обновлены.',
        ]);
    }

    public function updateModules(Request $request, GroupChannelBot $groupChannelBot): RedirectResponse
    {
        $validated = $request->validate([
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', Rule::in(array_keys(GroupChannelBot::MODULES))],
        ]);
        $enabled = array_fill_keys($validated['modules'] ?? [], true);
        $settings = [];

        foreach (GroupChannelBot::MODULES as $key => $label) {
            $current = data_get($groupChannelBot->module_settings, $key, []);
            $settings[$key] = array_merge(is_array($current) ? $current : [], [
                'enabled' => (bool) ($enabled[$key] ?? false),
            ]);
        }

        $groupChannelBot->update(['module_settings' => $settings]);

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Функции сохранены',
            'message' => 'Для этого чата применён выбранный набор функций.',
        ]);
    }

    public function sendTestMessage(GroupChannelBot $groupChannelBot): RedirectResponse
    {
        try {
            $api = $this->api($groupChannelBot);
            $chatId = $groupChannelBot->chat_id;

            if (! $chatId) {
                $chat = $this->telegram($api, 'getChat', [
                    'chat_id' => $this->chatReference($groupChannelBot->group_link),
                ]);
                $chatId = (string) $chat['id'];
                $groupChannelBot->update(['chat_id' => $chatId]);
            }

            $this->telegram($api, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => 'Тестовое сообщение SkyGuardian. Подключение Bot API работает.',
                'disable_notification' => true,
            ]);

            $groupChannelBot->update([
                'last_test_message_at' => now(),
                'last_test_message_error' => null,
            ]);

            return back()->with('toast', [
                'type' => 'success',
                'title' => 'Сообщение отправлено',
                'message' => 'Тестовая публикация успешно доставлена.',
            ]);
        } catch (Throwable $e) {
            report($e);
            $groupChannelBot->update([
                'last_test_message_at' => now(),
                'last_test_message_error' => $e->getMessage(),
            ]);

            return back()->with('toast', [
                'type' => 'error',
                'title' => 'Ошибка отправки',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function destroy(GroupChannelBot $groupChannelBot): RedirectResponse
    {
        $groupChannelBot->delete();

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Удалено',
            'message' => 'Запись удалена.',
        ]);
    }

    public function check(GroupChannelBot $groupChannelBot): RedirectResponse
    {
        try {
            $api = $this->api($groupChannelBot);
            $me = $this->telegram($api, 'getMe');
            $chatRef = $this->chatReference($groupChannelBot->group_link);
            $chat = $this->telegram($api, 'getChat', ['chat_id' => $chatRef]);
            $member = $this->telegram($api, 'getChatMember', [
                'chat_id' => $chat['id'],
                'user_id' => $me['id'],
            ]);

            $permissions = [
                'send_messages' => in_array($member['status'] ?? '', ['creator', 'administrator', 'member'], true),
                'delete_messages' => (bool) ($member['can_delete_messages'] ?? false),
                'pin_messages' => (bool) ($member['can_pin_messages'] ?? false),
                'restrict_members' => (bool) ($member['can_restrict_members'] ?? false),
                'invite_users' => (bool) ($member['can_invite_users'] ?? false),
                'manage_chat' => (bool) ($member['can_manage_chat'] ?? false),
                'manage_topics' => (bool) ($member['can_manage_topics'] ?? false),
                'manage_video_chats' => (bool) ($member['can_manage_video_chats'] ?? false),
                'post_messages' => (bool) ($member['can_post_messages'] ?? false),
                'edit_messages' => (bool) ($member['can_edit_messages'] ?? false),
                'is_administrator' => in_array($member['status'] ?? '', ['creator', 'administrator'], true),
            ];

            $groupChannelBot->update([
                'chat_id' => (string) $chat['id'],
                'chat_type' => (string) ($chat['type'] ?? $groupChannelBot->chat_type),
                'bot_username' => $me['username'] ?? null,
                'status' => $permissions['is_administrator'] ? 'connected' : 'limited',
                'permissions' => $permissions,
                'last_error' => null,
                'last_manual_check_at' => now(),
            ]);

            return back()->with('group_channel_check', [
                'bot' => $groupChannelBot->bot_name,
                'chat' => $chat['title'] ?? $groupChannelBot->group_name,
                'chat_id' => (string) $chat['id'],
                'username' => $me['username'] ?? null,
                'permissions' => $permissions,
                'error' => null,
            ]);
        } catch (Throwable $e) {
            report($e);

            $message = $e->getMessage();
            $groupChannelBot->update([
                'status' => 'error',
                'last_error' => $message,
                'last_manual_check_at' => now(),
            ]);

            return back()->with('group_channel_check', [
                'bot' => $groupChannelBot->bot_name,
                'chat' => $groupChannelBot->group_name,
                'permissions' => [],
                'error' => $message,
            ]);
        }
    }

    private function validated(Request $request, ?GroupChannelBot $bot = null): array
    {
        $data = $request->validate([
            'bot_name' => ['required', 'string', 'max:255'],
            'bot_token' => [$bot ? 'nullable' : 'required', 'string', 'max:255'],
            'admin_id' => ['required', 'string', 'max:64'],
            'group_name' => ['required', 'string', 'max:255'],
            'group_link' => ['required', 'url', 'max:255', Rule::unique('group_channel_bots', 'group_link')->ignore($bot?->id)],
            'chat_type' => ['required', Rule::in(['group', 'supergroup', 'channel'])],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        return $data;
    }

    private function api(GroupChannelBot $groupChannelBot): PendingRequest
    {
        return Http::baseUrl('https://api.telegram.org/bot'.$groupChannelBot->bot_token)
            ->acceptJson()
            ->timeout(15);
    }

    private function telegram(PendingRequest $api, string $method, array $payload = []): array
    {
        $response = $api->post($method, $payload)->throw()->json();

        if (! ($response['ok'] ?? false)) {
            throw new \RuntimeException($response['description'] ?? 'Ошибка Telegram API');
        }

        return $response['result'] ?? [];
    }

    private function chatReference(string $link): string
    {
        $path = trim((string) parse_url($link, PHP_URL_PATH), '/');

        if ($path === '' || str_starts_with($path, '+') || str_starts_with($path, 'joinchat/')) {
            throw new \RuntimeException('Для ручной проверки нужна публичная ссылка вида https://t.me/username.');
        }

        return '@'.explode('/', $path)[0];
    }

    private function disabledModules(): array
    {
        return collect(GroupChannelBot::MODULES)
            ->mapWithKeys(fn (string $label, string $key): array => [$key => ['enabled' => false]])
            ->all();
    }
}
