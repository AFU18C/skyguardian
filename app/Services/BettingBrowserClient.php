<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class BettingBrowserClient
{
    /** @param array<string, mixed> $bet */
    public function inspect(string $url, array $bet): array
    {
        $script = base_path('scripts/inspect-betting-event.mjs');
        if (! is_file($script)) {
            throw new RuntimeException('Сценарий поиска события в букмекерской линии не найден.');
        }

        $process = new Process([
            (string) config('skyguardian.betting.node_binary', 'node'),
            $script,
        ], base_path(), array_filter([
            'PLAYWRIGHT_BROWSERS_PATH' => config('skyguardian.betting.playwright_browsers_path'),
        ], fn (mixed $value): bool => is_string($value) && $value !== ''));
        $process->setInput(json_encode([
            'url' => $url,
            'home_team' => $bet['home_team'] ?? null,
            'away_team' => $bet['away_team'] ?? null,
            'market' => $bet['market'] ?? null,
            'timeout_ms' => max(5000, min(45000, (int) config(
                'skyguardian.betting.navigation_timeout_ms',
                20000,
            ))),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $process->setTimeout(max(30, min(120, (int) config(
            'skyguardian.betting.browser_timeout_seconds',
            75,
        ))));
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException('BETON не удалось проверить через браузер: '.mb_substr($error, 0, 1000));
        }

        $result = json_decode($process->getOutput(), true);
        if (! is_array($result) || ! is_string($result['body'] ?? null)) {
            throw new RuntimeException('BETON вернул некорректный результат браузерной проверки.');
        }

        return $result;
    }
}
