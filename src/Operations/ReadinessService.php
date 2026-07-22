<?php
declare(strict_types=1);

namespace SkyGuardian\Operations;

use SkyGuardian\Auth\AdminRepository;
use SkyGuardian\Storage\JsonStore;
use SkyGuardian\Telegram\AccountRepository;
use SkyGuardian\Telegram\BotConfigRepository;

final class ReadinessService
{
    public function __construct(
        private readonly string $storageDirectory,
        private readonly JsonStore $store,
    ) {
    }

    public function inspect(): array
    {
        $checks = [];

        $checks['storage'] = [
            'ok' => is_dir($this->storageDirectory) && is_writable($this->storageDirectory),
            'message' => is_dir($this->storageDirectory) && is_writable($this->storageDirectory)
                ? 'Storage is writable.'
                : 'Storage directory is missing or not writable.',
        ];

        try {
            $admin = (new AdminRepository($this->store))->find();
            $checks['admin'] = [
                'ok' => $admin !== null,
                'message' => $admin !== null ? 'Administrator is configured.' : 'Administrator is not configured.',
            ];
        } catch (\Throwable) {
            $checks['admin'] = ['ok' => false, 'message' => 'Administrator configuration cannot be read.'];
        }

        try {
            $bot = (new BotConfigRepository($this->store))->get();
            $botConfigured = trim((string) ($bot['token'] ?? '')) !== '';
            $checks['telegram_bot'] = [
                'ok' => $botConfigured,
                'required' => false,
                'message' => $botConfigured ? 'Telegram bot is configured.' : 'Telegram bot is not configured.',
            ];
        } catch (\Throwable) {
            $checks['telegram_bot'] = [
                'ok' => false,
                'required' => false,
                'message' => 'Telegram bot configuration cannot be read.',
            ];
        }

        try {
            $accounts = (new AccountRepository($this->store))->all();
            $connected = array_values(array_filter($accounts, static fn(array $account): bool =>
                ($account['enabled'] ?? false) === true && is_array($account['connected_user'] ?? null)
            ));
            $checks['telegram_accounts'] = [
                'ok' => $connected !== [],
                'required' => false,
                'count' => count($connected),
                'message' => $connected !== [] ? 'At least one Telegram account is connected.' : 'No enabled Telegram account is connected.',
            ];
        } catch (\Throwable) {
            $checks['telegram_accounts'] = [
                'ok' => false,
                'required' => false,
                'count' => 0,
                'message' => 'Telegram account configuration cannot be read.',
            ];
        }

        $ready = true;
        foreach ($checks as $check) {
            if (($check['required'] ?? true) && !($check['ok'] ?? false)) {
                $ready = false;
            }
        }

        return [
            'ready' => $ready,
            'checked_at' => gmdate(DATE_ATOM),
            'checks' => $checks,
        ];
    }
}
