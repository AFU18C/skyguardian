<?php

namespace App\Services\Telegram;

use App\Models\AlertSource;
use App\Models\NewsSource;
use App\Models\NewsTechnicalTelegramAccount;
use App\Models\TechnicalTelegramAccount;
use App\Models\TelegramApiCredential;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class TelethonAccountService
{
    private const MAX_ERROR_LENGTH = 2000;

    public function isConfigured(TechnicalTelegramAccount|NewsTechnicalTelegramAccount|null $account = null): bool
    {
        if ($account) {
            $account->loadMissing('telegramApiCredential');

            return filled($account->telegramApiCredential?->api_id)
                && filled($account->telegramApiCredential?->api_hash);
        }

        return TelegramApiCredential::query()->exists();
    }

    public function sendCode(string $phone, TechnicalTelegramAccount|NewsTechnicalTelegramAccount $account): array
    {
        return $this->run(['send-code', '--phone', $phone], $account);
    }

    public function signIn(string $phone, string $code, string $phoneCodeHash, TechnicalTelegramAccount|NewsTechnicalTelegramAccount $account, ?string $password = null): array
    {
        $arguments = ['sign-in', '--phone', $phone, '--code', $code, '--phone-code-hash', $phoneCodeHash];
        if (filled($password)) {
            $arguments[] = '--password';
            $arguments[] = $password;
        }

        return $this->run($arguments, $account);
    }

    public function status(TechnicalTelegramAccount|NewsTechnicalTelegramAccount $account): array
    {
        return $this->run(['status'], $account);
    }

    public function checkChat(TechnicalTelegramAccount|NewsTechnicalTelegramAccount $account, string $chat, string $mode): array
    {
        return $this->run(['check-chat', '--chat', $chat, '--mode', $mode], $account);
    }

    public function relayOnce(AlertSource|NewsSource $source): array
    {
        $source->loadMissing(['readerAccount.telegramApiCredential', 'publisherAccount.telegramApiCredential']);
        $reader = $source->readerAccount;
        $publisher = $source->publisherAccount;

        if (! $reader || ! $publisher) {
            throw new RuntimeException('Для источника не выбраны технические аккаунты.');
        }

        [$readerApiId, $readerApiHash] = $this->credentials($reader);
        [$publisherApiId, $publisherApiHash] = $this->credentials($publisher);

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

        return $this->execute($process, 120, 'Ошибка автопубликации Telegram.');
    }

    public function logout(TechnicalTelegramAccount|NewsTechnicalTelegramAccount $account): array
    {
        return $this->run(['logout'], $account);
    }

    public function resetSession(TechnicalTelegramAccount|NewsTechnicalTelegramAccount $account): void
    {
        $basePath = $this->sessionPath($account);

        foreach ([
            $basePath,
            $basePath.'.session',
            $basePath.'.session-journal',
            $basePath.'.session-shm',
            $basePath.'.session-wal',
            $basePath.'-journal',
            $basePath.'-shm',
            $basePath.'-wal',
        ] as $path) {
            if (is_file($path) && ! @unlink($path)) {
                throw new RuntimeException('Не удалось удалить файл Telegram-сессии.');
            }
        }
    }

    private function credentials(TechnicalTelegramAccount|NewsTechnicalTelegramAccount $account): array
    {
        $account->loadMissing('telegramApiCredential');
        $api = $account->telegramApiCredential;
        if (! filled($api?->api_id) || ! filled($api?->api_hash)) {
            throw new RuntimeException('Выберите Telegram API для технического аккаунта.');
        }

        return [$api->api_id, $api->api_hash];
    }

    private function sessionPath(TechnicalTelegramAccount|NewsTechnicalTelegramAccount $account): string
    {
        if ($account instanceof NewsTechnicalTelegramAccount) {
            $directory = storage_path('app/private/telegram/news_accounts');
        } else {
            $legacyPath = storage_path('app/private/telegram/technical');
            if ($account->is_primary && (is_file($legacyPath.'.session') || is_file($legacyPath))) {
                return $legacyPath;
            }
            $directory = storage_path('app/private/telegram/accounts');
        }

        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог Telegram-сессий.');
        }

        return $directory.'/'.$account->sessionKey();
    }

    private function run(array $arguments, TechnicalTelegramAccount|NewsTechnicalTelegramAccount $account): array
    {
        [$apiId, $apiHash] = $this->credentials($account);
        $process = new Process(array_merge([
            config('services.telegram.python', 'python3'),
            base_path('scripts/telegram_account.py'),
            '--api-id', (string) $apiId,
            '--api-hash', (string) $apiHash,
            '--session', $this->sessionPath($account),
        ], $arguments), base_path());

        return $this->execute($process, 45, 'Ошибка подключения к Telegram.');
    }

    private function execute(Process $process, int $timeout, string $fallbackMessage): array
    {
        $process->setTimeout($timeout);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException) {
            $process->stop(1);
            throw new RuntimeException('Telegram не ответил за отведённое время.');
        } catch (\Throwable) {
            // Ответ скрипта разбирается ниже, чтобы сохранить понятное сообщение Telegram.
        }

        $output = trim($process->getOutput() ?: $process->getErrorOutput());
        $result = json_decode($output, true);

        if (! is_array($result) || ! $process->isSuccessful() || ($result['ok'] ?? false) !== true) {
            $message = is_array($result) ? ($result['message'] ?? $fallbackMessage) : ($output ?: $fallbackMessage);
            throw new RuntimeException(mb_substr((string) $message, 0, self::MAX_ERROR_LENGTH));
        }

        return $result;
    }
}
