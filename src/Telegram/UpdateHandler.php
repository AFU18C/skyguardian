<?php
declare(strict_types=1);

namespace SkyGuardian\Telegram;

use SkyGuardian\Moderation\CaptchaService;
use SkyGuardian\Moderation\MessageInspector;
use SkyGuardian\Moderation\ModerationService;
use SkyGuardian\Moderation\ModerationSettingsRepository;
use SkyGuardian\Moderation\SpamGuard;
use SkyGuardian\Moderation\WelcomeService;
use SkyGuardian\Storage\JsonStore;

final class UpdateHandler
{
    public function __construct(private readonly JsonStore $store, private readonly BotApiClient $telegram) {}

    public function handle(array $update): void
    {
        $settings = (new ModerationSettingsRepository($this->store))->get();
        $captcha = new CaptchaService($this->store, $this->telegram);
        $welcome = new WelcomeService($this->store, $this->telegram);

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $query = $update['callback_query'];
            $data = (string) ($query['data'] ?? '');
            if (str_starts_with($data, 'captcha:')) {
                $token = substr($data, 8);
                $userId = (int) ($query['from']['id'] ?? 0);
                if ($captcha->confirmForUser($token, $userId)) {
                    $this->telegram->call('answerCallbackQuery', ['callback_query_id' => $query['id'], 'text' => 'Проверка пройдена']);
                } else {
                    $this->telegram->call('answerCallbackQuery', ['callback_query_id' => $query['id'], 'text' => 'Кнопка недействительна', 'show_alert' => true]);
                }
            }
            return;
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)) return;
        $chatId = (string) ($message['chat']['id'] ?? '');
        if ($chatId === '') return;

        foreach ((array) ($message['new_chat_members'] ?? []) as $user) {
            if (!is_array($user) || ($user['is_bot'] ?? false)) continue;
            $userId = (int) ($user['id'] ?? 0);
            if (($settings['captcha_enabled'] ?? false) === true) {
                $this->telegram->call('restrictChatMember', ['chat_id' => $chatId, 'user_id' => $userId, 'permissions' => ['can_send_messages' => false]]);
                $token = $captcha->create($chatId, $userId, max(30, (int) ($settings['captcha_timeout_seconds'] ?? 120)));
                $this->telegram->call('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => 'Подтвердите, что вы человек.',
                    'reply_markup' => ['inline_keyboard' => [[['text' => 'Я человек', 'callback_data' => 'captcha:' . $token]]]],
                ]);
            } elseif (($settings['welcome_enabled'] ?? false) === true) {
                $welcome->send($chatId, $user, (string) ($settings['welcome_text'] ?? 'Добро пожаловать, {first_name}!'), (int) ($settings['welcome_delete_after'] ?? 0));
            }
        }

        $fromId = (int) ($message['from']['id'] ?? 0);
        if ($fromId <= 0 || isset($message['new_chat_members'])) return;
        $member = $this->telegram->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $fromId]);
        $status = (string) ($member['result']['status'] ?? 'member');
        $isAdmin = in_array($status, ['administrator', 'creator'], true);
        $moderation = new ModerationService($this->telegram, new MessageInspector(), new SpamGuard($this->store));
        $moderation->handle($message, $settings, $isAdmin);
    }

    public function maintenance(): void
    {
        (new CaptchaService($this->store, $this->telegram))->expire();
        (new WelcomeService($this->store, $this->telegram))->processDeletionQueue();
    }
}
