<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WebsiteBetSearchService
{
    /**
     * @param  array<int, array{name:string,url:string,enabled?:bool}>  $sources
     * @param  array<int, string>  $keywords
     * @return array{messages: array<int, array<string, mixed>>, source_errors: array<int, array{source:string,error:string}>}
     */
    public function search(array $sources, array $keywords, int $limit): array
    {
        $messages = [];
        $errors = [];

        foreach ($sources as $source) {
            if (($source['enabled'] ?? true) !== true || count($messages) >= $limit) {
                continue;
            }

            $name = trim((string) ($source['name'] ?? ''));
            $url = trim((string) ($source['url'] ?? ''));

            try {
                $this->guardPublicUrl($url);
                $response = Http::timeout(20)->retry(1, 250, throw: false)->withHeaders([
                    'User-Agent' => 'SkyGuardian/1.0 (+manual betting search)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8',
                ])->get($url);

                if (! $response->successful()) {
                    throw new RuntimeException('HTTP '.$response->status());
                }

                $body = $response->body();
                if (strlen($body) > 2_000_000) {
                    $body = substr($body, 0, 2_000_000);
                }

                foreach ($this->candidateTexts($body, $keywords) as $index => $text) {
                    if (count($messages) >= $limit) {
                        break 2;
                    }

                    $messages[] = [
                        'id' => hash('sha256', $url.'|'.$index.'|'.$text),
                        'date' => now()->toIso8601String(),
                        'text' => $text,
                        'source_type' => 'website',
                        'source_name' => $name !== '' ? $name : (parse_url($url, PHP_URL_HOST) ?: 'Сайт'),
                        'url' => $url,
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = ['source' => $name !== '' ? $name : $url, 'error' => $e->getMessage()];
            }
        }

        return ['messages' => $messages, 'source_errors' => $errors];
    }

    /** @return array<int, string> */
    private function candidateTexts(string $body, array $keywords): array
    {
        $body = preg_replace('~<(script|style|noscript|svg)\b[^>]*>.*?</\1>~isu', ' ', $body) ?? $body;
        $body = preg_replace('~<(?:br|/p|/div|/article|/section|/li|/h[1-6]|/tr)\b[^>]*>~iu', "\n", $body) ?? $body;
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = collect(preg_split('/\R+/u', $text))
            ->map(fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? $line))
            ->filter(fn (string $line): bool => mb_strlen($line) >= 3)
            ->values();

        $needles = collect($keywords)
            ->map(fn (mixed $keyword): string => mb_strtolower(trim((string) $keyword)))
            ->filter()
            ->values();
        $candidates = [];

        foreach ($lines as $index => $line) {
            $lower = mb_strtolower($line);
            $matchesKeyword = $needles->isEmpty() || $needles->contains(fn (string $keyword): bool => str_contains($lower, $keyword));
            if (! $matchesKeyword) {
                continue;
            }

            $start = max(0, $index - 2);
            $candidate = $lines->slice($start, 5)->implode("\n");
            if (mb_strlen($candidate) > 2500) {
                $candidate = mb_substr($candidate, 0, 2500);
            }
            $candidates[hash('sha256', mb_strtolower($candidate))] = $candidate;
        }

        return array_values(array_slice($candidates, 0, 100, true));
    }

    private function guardPublicUrl(string $url): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('Некорректный адрес сайта.');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new RuntimeException('Локальные адреса запрещены.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('Локальные адреса запрещены.');
        }
    }
}
