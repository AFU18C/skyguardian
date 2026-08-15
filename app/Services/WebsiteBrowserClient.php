<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class WebsiteBrowserClient
{
    /**
     * @param  array<int, array{name:string,url:string,enabled?:bool}>  $sources
     * @param  array<int, string>  $keywords
     * @return array{documents:array<int,array<string,mixed>>,errors:array<int,array{source:string,error:string}>}
     */
    public function fetch(array $sources, array $keywords, int $freshnessHours): array
    {
        $script = base_path('scripts/search-websites.mjs');
        if (! is_file($script)) {
            throw new RuntimeException('Сценарий браузерного поиска не найден.');
        }

        $payload = json_encode([
            'sources' => array_values($sources),
            'keywords' => array_values($keywords),
            'freshness_hours' => max(1, min(720, $freshnessHours)),
            'concurrency' => max(1, min(5, (int) config('skyguardian.betting.browser_concurrency', 3))),
            'navigation_timeout_ms' => max(3000, min(30000, (int) config('skyguardian.betting.navigation_timeout_ms', 12000))),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $process = new Process([
            (string) config('skyguardian.betting.node_binary', 'node'),
            $script,
        ], base_path(), [
            'PLAYWRIGHT_BROWSERS_PATH' => (string) config('skyguardian.betting.playwright_browsers_path', ''),
        ]);
        $process->setInput($payload);
        $process->setTimeout(max(30, min(180, (int) config('skyguardian.betting.browser_timeout_seconds', 90))));
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            throw new RuntimeException('Браузерный поиск не выполнен: '.mb_substr($error, 0, 1000));
        }

        $result = json_decode($process->getOutput(), true);
        if (! is_array($result)) {
            throw new RuntimeException('Браузерный поиск вернул некорректный ответ.');
        }

        return [
            'documents' => array_values(array_filter($result['documents'] ?? [], 'is_array')),
            'errors' => array_values(array_filter($result['errors'] ?? [], 'is_array')),
        ];
    }
}
