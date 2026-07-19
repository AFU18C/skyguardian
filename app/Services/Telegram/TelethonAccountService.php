<?php

namespace App\Services\Telegram;

use App\Models\AlertSource;
use App\Models\TechnicalTelegramAccount;
use App\Models\TelegramApiCredential;
use RuntimeException;
use Symfony\Component\Process\Process;

class TelethonAccountService
{
    public function isConfigured(?TechnicalTelegramAccount $account = null): bool
    {
        if ($account) {
            $account->loadMissing('telegramApiCredential');
            return filled($account->telegramApiCredential?->api_id)
                && filled($account->telegramApiCredential?->api_hash);
        }

        return TelegramApiCredential::query()->exists();
    }

    public function sendCode(string $phone, TechnicalTelegramAccount $account): array
    {
        return $this->run(['send-code', '--phone', $phone], $account);
    }

    public function signIn(string $phone, string $code, string $phoneCodeHash, TechnicalTelegramAccount $account, ?string $password = null): array
    {
        $arguments = ['sign-in', '--phone', $phone, '--code', $code, '--phone-code-hash', $phoneCodeHash];
        if (filled($password)) {
            $arguments[] = '--password';
            $arguments[] = $password;
        }
        return $this->run($arguments, $account);
    }

    public function status(TechnicalTelegramAccount $account): array
    {
        return $this->run(['status'], $account);
    }

    public function checkChat(TechnicalTelegramAccount $account, string $chat, string $mode): array
    {
        return $this->run(['check-chat', '--chat', $chat, '--mode', $mode], $account);
    }

    public function relayOnce(AlertSource $source): array
    {
        $source->loadMissing([
            'readerAccount.telegramApiCredential',
            'publisherAccount.telegramApiCredential',
        ]);

        $reader = $source->readerAccount;
        $publisher = $source->publisherAccount;

        if (! $reader || ! $publisher) {
            throw new RuntimeException('Для источника не выбраны технические аккаунты.');
        }

        [$readerApiId, $readerApiHash] = $this->credentials($reader);
        [$publisherApiId, $publisherApiHash] = $this->credentials($publisher);

        if (! filled($readerApiId) || ! filled($readerApiHash) || ! filled($publisherApiId) || ! filled($publisherApiHash)) {
            throw new RuntimeException('Telegram API технического аккаунта не настроен.');
        }

        $process = new Process([
            config('services.telegram.python', 'python3'),
            base_path('scripts/telegram_alert_relay.py'),
            '--reader-api-id', (string) $readerApiId,
            '--reader-api-hash', (string) $readerApiHash,
            '--reader-session', $this->sessionPath($reader),
            '--publisher-api-id', (string) $publisherApiId,
            '--publisher-api-hash', (string) $publisherApiHash,
            '--publisher-session', $this->sessionPath($publisher),
            '--source', $source->source_chat,
            '--destination', $source->destination_chat,
            '--after-id', (string) ($source->last_source_message_id ?? 0),
            '--limit', '20',
        ], base_path());

        $process->setTimeout(120);
        $process->run();

        $output = trim($process->getOutput() ?: $process->getErrorOutput());
        $result = json_decode($output, true);

        if (! is_array($result)) {
            throw new RuntimeException($output ?: 'Telethon не вернул ответ автопубликации.');
        }
        if (! $process->isSuccessful() || ($result['ok'] ?? false) !== true) {
            throw new RuntimeException($result['message'] ?? 'Ошибка автопубликации Telegram.');
        }

        return $result;
    }

    public function logout(TechnicalTelegramAccount $account): array
    {
        return $this->run(['logout'], $account);
    }

    public function resetSession(TechnicalTelegramAccount $account): void
    {
        foreach ([$this->sessionPath($account), $this->sessionPath($account).'.session'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function credentials(TechnicalTelegramAccount $account): array
    {
        $account->loadMissing('telegramApiCredential');
        $api = $account->telegramApiCredential;
        return [$api?->api_id, $api?->api_hash];
    }

    private function sessionPath(TechnicalTelegramAccount $account): string
    {
        $legacyPath = storage_path('app/private/telegram/technical');
        if ($account->is_primary && (is_file($legacyPath.'.session') || is_file($legacyPath))) {
            return $legacyPath;
        }

        $sessionDirectory = storage_path('app/private/telegram/accounts');
        if (! is_dir($sessionDirectory) && ! mkdir($sessionDirectory, 0770, true) && ! is_dir($sessionDirectory)) {
            throw new RuntimeException('Не удалось создать каталог Telegram-сессий.');
        }
        return $sessionDirectory.'/'.$account->sessionKey();
    }

    private function run(array $arguments, TechnicalTelegramAccount $account): array
    {
        [$apiId, $apiHash] = $this->credentials($account);
        if (! filled($apiId) || ! filled($apiHash)) {
            throw new RuntimeException('Выберите Telegram API для технического аккаунта.');
        }

        $command = array_merge([
            config('services.telegram.python', 'python3'),
            base_path('scripts/telegram_account.py'),
            '--api-id', (string) $apiId,
            '--api-hash', (string) $apiHash,
            '--session', $this->sessionPath($account),
        ], $arguments);

        $process = new Process($command, base_path());
        $process->setTimeout(45);
        $process->run();
        $output = trim($process->getOutput() ?: $process->getErrorOutput());
        $result = json_decode($output, true);
        if (! is_array($result)) {
            throw new RuntimeException($output ?: 'Telethon не вернул ответ.');
        }
        if (! $process->isSuccessful() || ($result['ok'] ?? false) !== true) {
            throw new RuntimeException($result['message'] ?? 'Ошибка подключения к Telegram.');
        }
        return $result;
    }
}
