<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use App\Services\GroupChannelTelegramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class GroupChannelController extends Controller
{
    public function __construct(private readonly GroupChannelTelegramService $telegram) {}

    public function index(): View
    {
        return view('admin.group-channel', [
            'bots' => GroupChannelBot::query()
                ->with(['publications' => fn ($query) => $query->latest()])
                ->latest()
                ->paginate(12),
            'availableModules' => GroupChannelBot::MODULES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data = array_merge($data, $this->tokenMetadata((string) $data['bot_token']));
        $data['module_settings'] = GroupChannelBot::defaultModuleSettings();
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
        } else {
            $data = array_merge($data, $this->tokenMetadata((string) $data['bot_token']));
            $data['webhook_registered_at'] = null;
            $data['webhook_last_error'] = null;
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
        $settings = array_replace_recursive(
            GroupChannelBot::defaultModuleSettings(),
            $groupChannelBot->module_settings ?? [],
        );

        foreach (GroupChannelBot::MODULES as $key => $label) {
            $settings[$key]['enabled'] = (bool) ($enabled[$key] ?? false);
        }

        $groupChannelBot->update(['module_settings' => $settings]);

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Функции сохранены',
            'message' => 'Для этого чата применён выбранный набор функций.',
        ]);
    }

    public function updateModuleSettings(Request $request, GroupChannelBot $groupChannelBot): RedirectResponse
    {
        $data = $request->validate([
            'settings.antispam.delete_links' => ['nullable', 'boolean'],
            'settings.antispam.delete_new_member_messages' => ['nullable', 'boolean'],
            'settings.antispam.new_member_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'settings.antispam.forbidden_words_text' => ['nullable', 'string', 'max:10000'],
            'settings.antispam.message_limit' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'settings.antispam.message_limit_period_seconds' => ['nullable', 'integer', 'min:5', 'max:86400'],
            'settings.antispam.block_duplicates' => ['nullable', 'boolean'],
            'settings.antispam.max_mentions' => ['nullable', 'integer', 'min:0', 'max:100'],
            'settings.antispam.delete_short_messages' => ['nullable', 'boolean'],
            'settings.antispam.min_length' => ['nullable', 'integer', 'min:1', 'max:100'],
            'settings.antispam.suspicious_symbols' => ['nullable', 'boolean'],
            'settings.welcome.text' => ['nullable', 'string', 'max:4096'],
            'settings.welcome.rules' => ['nullable', 'string', 'max:4096'],
            'settings.welcome.buttons_text' => ['nullable', 'string', 'max:5000'],
            'settings.welcome.delete_after_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'settings.subscription_check.channels_text' => ['nullable', 'string', 'max:10000'],
            'settings.join_requests.auto_approve' => ['nullable', 'boolean'],
            'settings.join_requests.auto_decline_bots' => ['nullable', 'boolean'],
            'settings.human_verification.mode' => ['nullable', Rule::in(['button', 'question', 'captcha'])],
            'settings.human_verification.question' => ['nullable', 'string', 'max:1000'],
            'settings.human_verification.answer' => ['nullable', 'string', 'max:255'],
            'settings.human_verification.timeout_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'settings.warnings.mute_after' => ['nullable', 'integer', 'min:1', 'max:100'],
            'settings.warnings.mute_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'settings.warnings.ban_after' => ['nullable', 'integer', 'min:1', 'max:100'],
            'settings.newcomer_restrictions.minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'settings.newcomer_restrictions.block_links' => ['nullable', 'boolean'],
            'settings.newcomer_restrictions.block_files' => ['nullable', 'boolean'],
            'settings.newcomer_restrictions.block_messages' => ['nullable', 'boolean'],
            'settings.slow_mode.messages' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'settings.slow_mode.period_seconds' => ['nullable', 'integer', 'min:5', 'max:86400'],
        ]);

        $incoming = $data['settings'] ?? [];
        $booleanSettings = [
            'antispam' => [
                'delete_links',
                'delete_new_member_messages',
                'block_duplicates',
                'delete_short_messages',
                'suspicious_symbols',
            ],
            'join_requests' => ['auto_approve', 'auto_decline_bots'],
            'newcomer_restrictions' => ['block_links', 'block_files', 'block_messages'],
        ];

        foreach ($booleanSettings as $module => $keys) {
            if (! $groupChannelBot->moduleEnabled($module)) {
                continue;
            }

            foreach ($keys as $key) {
                data_set($incoming, $module.'.'.$key, $request->boolean("settings.{$module}.{$key}"));
            }
        }

        $settings = array_replace_recursive(
            GroupChannelBot::defaultModuleSettings(),
            $groupChannelBot->module_settings ?? [],
            $incoming,
        );

        if ($groupChannelBot->moduleEnabled('antispam')) {
            $settings['antispam']['forbidden_words'] = $this->lines(
                data_get($incoming, 'antispam.forbidden_words_text', ''),
            );
            unset($settings['antispam']['forbidden_words_text']);
        }

        if ($groupChannelBot->moduleEnabled('subscription_check')) {
            $settings['subscription_check']['channels'] = $this->lines(
                data_get($incoming, 'subscription_check.channels_text', ''),
            );
            unset($settings['subscription_check']['channels_text']);
        }

        if ($groupChannelBot->moduleEnabled('welcome')) {
            $settings['welcome']['buttons'] = $this->buttons(
                data_get($incoming, 'welcome.buttons_text', ''),
            );
            unset($settings['welcome']['buttons_text']);
        }

        $groupChannelBot->update(['module_settings' => $settings]);

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Настройки сохранены',
            'message' => 'Параметры функций обновлены только для этого чата.',
        ]);
    }

    public function sendTestMessage(GroupChannelBot $groupChannelBot): RedirectResponse
    {
        try {
            $this->ensureTokenMetadata($groupChannelBot);
            $chatId = $groupChannelBot->chat_id;

            if (! $chatId) {
                $chat = $this->telegram->request($groupChannelBot, 'getChat', [
                    'chat_id' => $this->chatReference($groupChannelBot->group_link),
                ]);
                $chatId = (string) $chat['id'];
                $groupChannelBot->update(['chat_id' => $chatId]);
            }

            $this->telegram->request($groupChannelBot, 'sendMessage', [
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

    public function registerWebhook(GroupChannelBot $groupChannelBot): RedirectResponse
    {
        try {
            $this->ensureTokenMetadata($groupChannelBot);
            $url = route('group-channel.webhook', [
                'fingerprint' => $groupChannelBot->token_fingerprint,
                'secret' => $groupChannelBot->webhook_secret,
            ]);
            $this->telegram->request($groupChannelBot, 'setWebhook', [
                'url' => $url,
                'secret_token' => $groupChannelBot->webhook_secret,
                'allowed_updates' => json_encode([
                    'message',
                    'edited_message',
                    'chat_join_request',
                    'callback_query',
                    'my_chat_member',
                ]),
                'drop_pending_updates' => false,
            ]);

            GroupChannelBot::query()
                ->where('token_fingerprint', $groupChannelBot->token_fingerprint)
                ->update([
                    'webhook_registered_at' => now(),
                    'webhook_last_error' => null,
                ]);

            return back()->with('toast', [
                'type' => 'success',
                'title' => 'Webhook включён',
                'message' => 'Бот принимает события для всех добавленных групп и каналов.',
            ]);
        } catch (Throwable $e) {
            report($e);
            $groupChannelBot->update(['webhook_last_error' => $e->getMessage()]);

            return back()->with('toast', [
                'type' => 'error',
                'title' => 'Ошибка webhook',
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
            $this->ensureTokenMetadata($groupChannelBot);
            $me = $this->telegram->request($groupChannelBot, 'getMe');
            $chatRef = $this->chatReference($groupChannelBot->group_link);
            $chat = $this->telegram->request($groupChannelBot, 'getChat', ['chat_id' => $chatRef]);
            $member = $this->telegram->request($groupChannelBot, 'getChatMember', [
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

    private function tokenMetadata(string $token): array
    {
        $fingerprint = hash('sha256', $token);
        $existing = GroupChannelBot::query()
            ->where('token_fingerprint', $fingerprint)
            ->first();

        return [
            'token_fingerprint' => $fingerprint,
            'webhook_secret' => $existing?->webhook_secret ?: Str::random(48),
        ];
    }

    private function ensureTokenMetadata(GroupChannelBot $bot): void
    {
        if ($bot->token_fingerprint && $bot->webhook_secret) {
            return;
        }

        $bot->update($this->tokenMetadata((string) $bot->bot_token));
        $bot->refresh();
    }

    private function chatReference(string $link): string
    {
        $path = trim((string) parse_url($link, PHP_URL_PATH), '/');

        if ($path === '' || str_starts_with($path, '+') || str_starts_with($path, 'joinchat/')) {
            throw new \RuntimeException('Для ручной проверки нужна публичная ссылка вида https://t.me/username.');
        }

        return '@'.explode('/', $path)[0];
    }

    private function lines(?string $value): array
    {
        return collect(preg_split('/[\r\n,]+/u', (string) $value))
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function buttons(?string $value): array
    {
        return collect(preg_split('/\R/u', (string) $value))
            ->map(function (string $line): array {
                [$text, $url] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');

                return $text !== '' && $url !== '' ? [['text' => $text, 'url' => $url]] : [];
            })
            ->filter()
            ->values()
            ->all();
    }
}
