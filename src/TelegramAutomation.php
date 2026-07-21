<?php
declare(strict_types=1);

final class TelegramAutomation
{
    private string $storageDir;
    private string $configFile;
    private string $stateFile;
    private string $logFile;

    public function __construct(string $storageDir)
    {
        $this->storageDir = rtrim($storageDir, '/');
        $this->configFile = $this->storageDir . '/telegram-automation.json';
        $this->stateFile = $this->storageDir . '/telegram-automation-state.json';
        $this->logFile = $this->storageDir . '/telegram-automation.log';
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0750, true);
        }
    }

    public function save(array $input, string $baseUrl): array
    {
        $token = trim((string)($input['bot_token'] ?? ''));
        $chatId = trim((string)($input['chat_id'] ?? ''));
        if (!preg_match('/^\d{6,12}:[A-Za-z0-9_-]{30,}$/', $token) || !preg_match('/^-?\d+$/', $chatId)) {
            throw new InvalidArgumentException('Проверьте токен и Chat ID.');
        }

        $configs = $this->readJson($this->configFile);
        $key = hash('sha256', $token . ':' . $chatId);
        $existing = is_array($configs[$key] ?? null) ? $configs[$key] : [];
        $secret = (string)($existing['secret'] ?? bin2hex(random_bytes(24)));
        $mode = in_array(($input['mode'] ?? 'webhook'), ['webhook', 'polling'], true) ? (string)$input['mode'] : 'webhook';

        $config = [
            'id' => $key,
            'secret' => $secret,
            'bot_token' => $token,
            'chat_id' => $chatId,
            'enabled' => isset($input['enabled']),
            'mode' => $mode,
            'anti_spam' => isset($input['anti_spam']),
            'spam_limit' => max(2, min(20, (int)($input['spam_limit'] ?? 5))),
            'spam_window' => max(3, min(120, (int)($input['spam_window'] ?? 10))),
            'spam_mute' => max(60, min(604800, (int)($input['spam_mute'] ?? 3600))),
            'filter_links' => isset($input['filter_links']),
            'forbidden_words' => array_values(array_unique(array_filter(array_map(
                static fn(string $word): string => mb_strtolower(trim($word)),
                preg_split('/[,\r\n]+/u', (string)($input['forbidden_words'] ?? '')) ?: []
            )))),
            'captcha' => isset($input['captcha']),
            'captcha_timeout' => max(30, min(3600, (int)($input['captcha_timeout'] ?? 180))),
            'welcome' => isset($input['welcome']),
            'welcome_text' => mb_substr(trim((string)($input['welcome_text'] ?? 'Добро пожаловать, {name}!')), 0, 3500),
            'welcome_delete_after' => max(0, min(86400, (int)($input['welcome_delete_after'] ?? 60))),
            'violation_delete' => isset($input['violation_delete']),
            'updated_at' => date(DATE_ATOM),
        ];
        $configs[$key] = $config;
        $this->writeJson($this->configFile, $configs);

        $webhookUrl = rtrim($baseUrl, '/') . '/telegram-webhook.php?key=' . rawurlencode($secret);
        if ($config['enabled'] && $mode === 'webhook') {
            $this->api($token, 'setWebhook', [
                'url' => $webhookUrl,
                'secret_token' => $secret,
                'allowed_updates' => json_encode(['message', 'callback_query', 'chat_member'], JSON_UNESCAPED_SLASHES),
                'drop_pending_updates' => 'false',
            ]);
        } else {
            $this->api($token, 'deleteWebhook', ['drop_pending_updates' => 'false']);
        }

        return $this->publicConfig($config, $webhookUrl);
    }

    public function findBySecret(string $secret): ?array
    {
        foreach ($this->readJson($this->configFile) as $config) {
            if (is_array($config) && hash_equals((string)($config['secret'] ?? ''), $secret)) {
                return $config;
            }
        }
        return null;
    }

    public function pollingConfigs(): array
    {
        return array_values(array_filter($this->readJson($this->configFile), static fn($c): bool =>
            is_array($c) && ($c['enabled'] ?? false) === true && ($c['mode'] ?? '') === 'polling'
        ));
    }

    public function status(string $token, string $chatId, string $baseUrl): array
    {
        $key = hash('sha256', $token . ':' . $chatId);
        $config = $this->readJson($this->configFile)[$key] ?? null;
        if (!is_array($config)) {
            return ['configured' => false];
        }
        $info = $this->api($token, 'getWebhookInfo');
        return array_merge(['configured' => true], $this->publicConfig(
            $config,
            rtrim($baseUrl, '/') . '/telegram-webhook.php?key=' . rawurlencode((string)$config['secret'])
        ), ['telegram' => [
            'url' => (string)($info['url'] ?? ''),
            'pending_update_count' => (int)($info['pending_update_count'] ?? 0),
            'last_error_message' => (string)($info['last_error_message'] ?? ''),
        ]]);
    }

    public function process(array $config, array $update): void
    {
        if (($config['enabled'] ?? false) !== true) {
            return;
        }
        if (isset($update['callback_query'])) {
            $this->captchaCallback($config, (array)$update['callback_query']);
            return;
        }
        $message = $update['message'] ?? null;
        if (!is_array($message) || (string)($message['chat']['id'] ?? '') !== (string)$config['chat_id']) {
            return;
        }

        if (!empty($message['new_chat_members'])) {
            foreach ((array)$message['new_chat_members'] as $member) {
                if (is_array($member) && !($member['is_bot'] ?? false)) {
                    $this->newMember($config, $message, $member);
                }
            }
            return;
        }

        $user = (array)($message['from'] ?? []);
        $userId = (string)($user['id'] ?? '');
        if ($userId === '' || ($user['is_bot'] ?? false) || $this->isAdmin($config, $userId)) {
            return;
        }
        $text = (string)($message['text'] ?? $message['caption'] ?? '');
        $reason = null;
        if (($config['filter_links'] ?? false) && preg_match('~(?:https?://|www\.|t\.me/|telegram\.me/)~iu', $text)) {
            $reason = 'ссылка';
        }
        if ($reason === null && $text !== '') {
            $lower = mb_strtolower($text);
            foreach ((array)($config['forbidden_words'] ?? []) as $word) {
                if ($word !== '' && mb_strpos($lower, (string)$word) !== false) {
                    $reason = 'запрещённое слово';
                    break;
                }
            }
        }
        if ($reason === null && ($config['anti_spam'] ?? false)) {
            $reason = $this->recordMessage($config, $userId) ? 'флуд' : null;
        }
        if ($reason !== null) {
            $this->moderate($config, $message, $userId, $reason);
        }
    }

    private function newMember(array $config, array $message, array $member): void
    {
        $userId = (string)$member['id'];
        $name = trim((string)($member['first_name'] ?? '') . ' ' . (string)($member['last_name'] ?? ''));
        if (($config['captcha'] ?? false) === true) {
            $this->api($config['bot_token'], 'restrictChatMember', [
                'chat_id' => $config['chat_id'],
                'user_id' => $userId,
                'permissions' => json_encode(['can_send_messages' => false], JSON_UNESCAPED_SLASHES),
                'until_date' => time() + (int)$config['captcha_timeout'],
            ]);
            $sent = $this->api($config['bot_token'], 'sendMessage', [
                'chat_id' => $config['chat_id'],
                'text' => ($name !== '' ? $name : 'Новый участник') . ', подтвердите, что вы человек.',
                'reply_markup' => json_encode(['inline_keyboard' => [[[
                    'text' => '✅ Я человек',
                    'callback_data' => 'sgcaptcha:' . $userId,
                ]]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $state = $this->readJson($this->stateFile);
            $state['captcha'][$config['id'] . ':' . $userId] = [
                'message_id' => (int)($sent['message_id'] ?? 0),
                'expires_at' => time() + (int)$config['captcha_timeout'],
                'name' => $name,
            ];
            $this->writeJson($this->stateFile, $state);
            $this->log('captcha_started', $config, ['user_id' => $userId]);
        } else {
            $this->welcome($config, $userId, $name);
        }
    }

    private function captchaCallback(array $config, array $callback): void
    {
        $data = (string)($callback['data'] ?? '');
        if (!str_starts_with($data, 'sgcaptcha:')) return;
        $expected = substr($data, 10);
        $actual = (string)($callback['from']['id'] ?? '');
        if ($expected === '' || !hash_equals($expected, $actual)) {
            $this->api($config['bot_token'], 'answerCallbackQuery', [
                'callback_query_id' => $callback['id'],
                'text' => 'Эта кнопка предназначена другому участнику.',
                'show_alert' => 'true',
            ]);
            return;
        }
        $state = $this->readJson($this->stateFile);
        $key = $config['id'] . ':' . $actual;
        $captcha = $state['captcha'][$key] ?? null;
        if (!is_array($captcha) || (int)($captcha['expires_at'] ?? 0) < time()) {
            $this->api($config['bot_token'], 'answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'Проверка уже истекла.']);
            return;
        }
        $permissions = ['can_send_messages' => true, 'can_send_audios' => true, 'can_send_documents' => true, 'can_send_photos' => true, 'can_send_videos' => true, 'can_send_video_notes' => true, 'can_send_voice_notes' => true, 'can_send_polls' => true, 'can_send_other_messages' => true, 'can_add_web_page_previews' => true, 'can_invite_users' => true];
        $this->api($config['bot_token'], 'restrictChatMember', ['chat_id' => $config['chat_id'], 'user_id' => $actual, 'permissions' => json_encode($permissions, JSON_UNESCAPED_SLASHES)]);
        $this->api($config['bot_token'], 'answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'Проверка пройдена.']);
        if ((int)$captcha['message_id'] > 0) {
            $this->safeApi($config['bot_token'], 'deleteMessage', ['chat_id' => $config['chat_id'], 'message_id' => $captcha['message_id']]);
        }
        unset($state['captcha'][$key]);
        $this->writeJson($this->stateFile, $state);
        $this->welcome($config, $actual, (string)($captcha['name'] ?? ''));
        $this->log('captcha_passed', $config, ['user_id' => $actual]);
    }

    private function welcome(array $config, string $userId, string $name): void
    {
        if (($config['welcome'] ?? false) !== true || trim((string)$config['welcome_text']) === '') return;
        $mention = '<a href="tg://user?id=' . htmlspecialchars($userId, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($name !== '' ? $name : 'участник', ENT_QUOTES, 'UTF-8') . '</a>';
        $text = str_replace(['{name}', '{user_id}'], [$mention, $userId], (string)$config['welcome_text']);
        $sent = $this->api($config['bot_token'], 'sendMessage', ['chat_id' => $config['chat_id'], 'text' => $text, 'parse_mode' => 'HTML']);
        $after = (int)($config['welcome_delete_after'] ?? 0);
        if ($after > 0) {
            $state = $this->readJson($this->stateFile);
            $state['delete'][] = ['at' => time() + $after, 'token' => $config['bot_token'], 'chat_id' => $config['chat_id'], 'message_id' => (int)($sent['message_id'] ?? 0)];
            $this->writeJson($this->stateFile, $state);
        }
    }

    private function recordMessage(array $config, string $userId): bool
    {
        $state = $this->readJson($this->stateFile);
        $key = $config['id'] . ':' . $userId;
        $now = time();
        $window = (int)$config['spam_window'];
        $events = array_values(array_filter((array)($state['spam'][$key] ?? []), static fn($time): bool => (int)$time >= $now - $window));
        $events[] = $now;
        $state['spam'][$key] = $events;
        $this->writeJson($this->stateFile, $state);
        return count($events) >= (int)$config['spam_limit'];
    }

    private function moderate(array $config, array $message, string $userId, string $reason): void
    {
        if (($config['violation_delete'] ?? true) && isset($message['message_id'])) {
            $this->safeApi($config['bot_token'], 'deleteMessage', ['chat_id' => $config['chat_id'], 'message_id' => $message['message_id']]);
        }
        if ($reason === 'флуд') {
            $this->safeApi($config['bot_token'], 'restrictChatMember', [
                'chat_id' => $config['chat_id'],
                'user_id' => $userId,
                'permissions' => json_encode(['can_send_messages' => false], JSON_UNESCAPED_SLASHES),
                'until_date' => time() + (int)$config['spam_mute'],
            ]);
        }
        $this->log('moderated', $config, ['user_id' => $userId, 'reason' => $reason, 'message_id' => $message['message_id'] ?? null]);
    }

    private function isAdmin(array $config, string $userId): bool
    {
        try {
            $member = $this->api($config['bot_token'], 'getChatMember', ['chat_id' => $config['chat_id'], 'user_id' => $userId]);
            return in_array((string)($member['status'] ?? ''), ['administrator', 'creator'], true);
        } catch (Throwable) {
            return false;
        }
    }

    public function runMaintenance(): void
    {
        $state = $this->readJson($this->stateFile);
        $remaining = [];
        foreach ((array)($state['delete'] ?? []) as $item) {
            if ((int)($item['at'] ?? 0) <= time()) {
                $this->safeApi((string)$item['token'], 'deleteMessage', ['chat_id' => $item['chat_id'], 'message_id' => $item['message_id']]);
            } else {
                $remaining[] = $item;
            }
        }
        $state['delete'] = $remaining;
        foreach ((array)($state['captcha'] ?? []) as $key => $captcha) {
            if ((int)($captcha['expires_at'] ?? 0) <= time()) unset($state['captcha'][$key]);
        }
        $this->writeJson($this->stateFile, $state);
    }

    public function api(string $token, string $method, array $fields = []): mixed
    {
        $handle = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
        $body = curl_exec($handle);
        $error = curl_error($handle);
        $code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $data = json_decode((string)$body, true);
        if ($body === false || $error !== '' || !is_array($data) || $code >= 400 || ($data['ok'] ?? false) !== true) {
            throw new RuntimeException((string)($data['description'] ?? $error ?: 'Telegram API недоступен.'));
        }
        return $data['result'] ?? true;
    }

    private function safeApi(string $token, string $method, array $fields): void
    {
        try { $this->api($token, $method, $fields); } catch (Throwable $e) { $this->logRaw('api_error', ['method' => $method, 'error' => $e->getMessage()]); }
    }

    private function publicConfig(array $config, string $webhookUrl): array
    {
        return [
            'enabled' => (bool)$config['enabled'],
            'mode' => $config['mode'],
            'webhook_url' => preg_replace('/key=[^&]+/', 'key=••••••', $webhookUrl),
            'updated_at' => $config['updated_at'],
        ];
    }

    private function readJson(string $file): array
    {
        if (!is_file($file)) return [];
        $handle = fopen($file, 'rb');
        if (!$handle) return [];
        flock($handle, LOCK_SH);
        $content = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        $data = json_decode((string)$content, true);
        return is_array($data) ? $data : [];
    }

    private function writeJson(string $file, array $data): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить настройки автоматизации.');
        chmod($tmp, 0600);
        if (!rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('Не удалось применить настройки автоматизации.'); }
    }

    private function log(string $event, array $config, array $context = []): void
    {
        $this->logRaw($event, array_merge(['chat_id' => $config['chat_id']], $context));
    }

    private function logRaw(string $event, array $context): void
    {
        $line = json_encode(['time' => date(DATE_ATOM), 'event' => $event, 'context' => $context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        @chmod($this->logFile, 0600);
    }
}
