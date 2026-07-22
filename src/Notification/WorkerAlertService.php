<?php
declare(strict_types=1);

namespace SkyGuardian\Notification;

final class WorkerAlertService
{
    public function __construct(
        private readonly string $storageDir,
        private readonly int $cooldownSeconds = 900,
    ) {
    }

    /**
     * @param callable(string, string): void $sender Receives bot token and chat id.
     */
    public function notify(
        string $scope,
        string $severity,
        string $message,
        callable $sender,
        ?int $now = null,
    ): array {
        $now ??= time();
        $scope = in_array($scope, ['news', 'alerts'], true) ? $scope : 'unknown';
        $severity = in_array($severity, ['warning', 'critical', 'recovery'], true) ? $severity : 'warning';
        $message = $this->redact(trim($message));
        if ($message === '') {
            throw new \InvalidArgumentException('Alert message cannot be empty.');
        }

        $config = $this->readJson($this->storageDir . '/worker-notifications.json');
        if (!(bool) ($config['enabled'] ?? false)) {
            return ['sent' => false, 'reason' => 'disabled'];
        }

        $token = trim((string) ($config['bot_token'] ?? ''));
        $chatId = trim((string) ($config['chat_id'] ?? ''));
        if (!preg_match('/^\d{6,12}:[A-Za-z0-9_-]{30,}$/', $token) || !preg_match('/^-?\d+$/', $chatId)) {
            return ['sent' => false, 'reason' => 'not_configured'];
        }

        $fingerprint = hash('sha256', $scope . '|' . $severity . '|' . mb_strtolower($message));
        $stateFile = $this->storageDir . '/worker-notification-state.json';
        $state = $this->readJson($stateFile);
        $previous = is_array($state[$fingerprint] ?? null) ? $state[$fingerprint] : [];
        $lastSentAt = (int) ($previous['sent_at'] ?? 0);

        if ($severity !== 'recovery' && $lastSentAt > 0 && $now - $lastSentAt < $this->cooldownSeconds) {
            $this->appendJournal($scope, $severity, $message, 'suppressed', $now);
            return ['sent' => false, 'reason' => 'cooldown'];
        }

        $text = $this->formatMessage($scope, $severity, $message, $now);
        try {
            $sender($token, $chatId, $text);
            $state[$fingerprint] = ['sent_at' => $now, 'scope' => $scope, 'severity' => $severity];
            $this->writeJson($stateFile, $state);
            $this->appendJournal($scope, $severity, $message, 'sent', $now);
            return ['sent' => true, 'reason' => null];
        } catch (\Throwable $exception) {
            $this->appendJournal($scope, $severity, $message . ' | Delivery: ' . $this->redact($exception->getMessage()), 'failed', $now);
            return ['sent' => false, 'reason' => 'delivery_failed'];
        }
    }

    public function journal(int $limit = 50): array
    {
        $items = $this->readJson($this->storageDir . '/worker-notification-journal.json');
        if (!array_is_list($items)) {
            $items = [];
        }
        return array_slice(array_reverse($items), 0, max(1, min(200, $limit)));
    }

    public function redact(string $value): string
    {
        $value = preg_replace('/\b\d{6,12}:[A-Za-z0-9_-]{20,}\b/', '[REDACTED_BOT_TOKEN]', $value) ?? $value;
        $value = preg_replace('/(?i)(api[_ -]?hash|password|secret|token)\s*[:=]\s*\S+/', '$1=[REDACTED]', $value) ?? $value;
        return mb_substr($value, 0, 1000);
    }

    private function formatMessage(string $scope, string $severity, string $message, int $now): string
    {
        $icon = match ($severity) {
            'critical' => '🔴',
            'recovery' => '🟢',
            default => '🟠',
        };
        $scopeLabel = $scope === 'alerts' ? 'Воздушная тревога' : ($scope === 'news' ? 'Новости' : 'Worker');
        return $icon . ' SkyGuardian: ' . $scopeLabel . "\n"
            . 'Статус: ' . $severity . "\n"
            . 'Время UTC: ' . gmdate('Y-m-d H:i:s', $now) . "\n"
            . $message;
    }

    private function appendJournal(string $scope, string $severity, string $message, string $delivery, int $now): void
    {
        $file = $this->storageDir . '/worker-notification-journal.json';
        $journal = $this->readJson($file);
        if (!array_is_list($journal)) {
            $journal = [];
        }
        $journal[] = [
            'id' => bin2hex(random_bytes(8)),
            'created_at' => gmdate(DATE_ATOM, $now),
            'scope' => $scope,
            'severity' => $severity,
            'delivery' => $delivery,
            'message' => $this->redact($message),
        ];
        if (count($journal) > 500) {
            $journal = array_slice($journal, -500);
        }
        $this->writeJson($file, $journal);
    }

    private function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        if (!is_string($raw)) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function writeJson(string $file, array $data): void
    {
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0770, true) && !is_dir($this->storageDir)) {
            throw new \RuntimeException('Cannot create storage directory.');
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temp = tempnam($this->storageDir, '.notification-');
        if ($temp === false) {
            throw new \RuntimeException('Cannot create notification temp file.');
        }
        try {
            if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false) {
                throw new \RuntimeException('Cannot write notification data.');
            }
            chmod($temp, 0600);
            if (!rename($temp, $file)) {
                throw new \RuntimeException('Cannot replace notification data.');
            }
            chmod($file, 0600);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
