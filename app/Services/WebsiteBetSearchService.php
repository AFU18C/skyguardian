<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class WebsiteBetSearchService
{
    private readonly WebsiteBrowserClient $browser;

    private readonly PublicUrlGuard $urlGuard;

    public function __construct(
        ?WebsiteBrowserClient $browser = null,
        ?PublicUrlGuard $urlGuard = null,
    ) {
        $this->browser = $browser ?? app(WebsiteBrowserClient::class);
        $this->urlGuard = $urlGuard ?? app(PublicUrlGuard::class);
    }

    /**
     * @param  array<int, array{name:string,url:string,enabled?:bool}>  $sources
     * @param  array<int, string>  $keywords
     * @return array{messages: array<int, array<string, mixed>>, source_errors: array<int, array{source:string,error:string}>}
     */
    public function search(array $sources, array $keywords, int $limit, int $freshnessHours = 24): array
    {
        $messages = [];
        $errors = [];
        $maximumSources = max(1, min(50, (int) config('skyguardian.betting.maximum_website_sources_per_run', 20)));
        $enabled = collect($sources)
            ->filter(fn (mixed $source): bool => is_array($source)
                && ($source['enabled'] ?? true) === true
                && trim((string) ($source['url'] ?? '')) !== '')
            ->take($maximumSources)
            ->values();

        $safeSources = $enabled->filter(function (array $source) use (&$errors): bool {
            try {
                $this->urlGuard->inspect((string) $source['url']);

                return true;
            } catch (\Throwable $e) {
                $errors[] = [
                    'source' => trim((string) ($source['name'] ?? '')) ?: (string) $source['url'],
                    'error' => $e->getMessage(),
                ];

                return false;
            }
        })->all();

        if ($safeSources === []) {
            return ['messages' => [], 'source_errors' => $errors];
        }

        try {
            $result = $this->browser->fetch($safeSources, $keywords, $freshnessHours);
        } catch (\Throwable $e) {
            foreach ($safeSources as $source) {
                $errors[] = [
                    'source' => trim((string) ($source['name'] ?? '')) ?: (string) $source['url'],
                    'error' => $e->getMessage(),
                ];
            }

            return ['messages' => [], 'source_errors' => $errors];
        }

        $errors = array_merge($errors, $result['errors']);
        $cutoff = CarbonImmutable::now()->subHours(max(1, min(720, $freshnessHours)));

        foreach ($result['documents'] as $document) {
            $publishedAt = $this->publishedAt($document['published_at'] ?? null);
            if ($publishedAt !== null && $publishedAt->lt($cutoff)) {
                continue;
            }

            $sourceUrl = $this->safeDocumentUrl($document);
            if ($sourceUrl === '') {
                continue;
            }
            $sourceName = trim((string) ($document['name'] ?? '')) ?: (parse_url($sourceUrl, PHP_URL_HOST) ?: 'Сайт');
            $documentText = trim(implode("\n", array_filter([
                trim((string) ($document['title'] ?? '')),
                trim((string) ($document['text'] ?? '')),
            ])));

            foreach ($this->candidateTexts($documentText, $keywords) as $text) {
                if (count($messages) >= $limit) {
                    break 2;
                }

                $messages[] = [
                    'id' => hash('sha256', mb_strtolower($sourceUrl.'|'.$text)),
                    'date' => $publishedAt?->toIso8601String(),
                    'fetched_at' => $document['fetched_at'] ?? now()->toIso8601String(),
                    'text' => $text,
                    'source_type' => 'website',
                    'source_name' => $sourceName,
                    'url' => $sourceUrl,
                ];
            }
        }

        return ['messages' => $messages, 'source_errors' => $errors];
    }

    /** @return array<int, string> */
    private function candidateTexts(string $text, array $keywords): array
    {
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
            $matchesKeyword = $needles->isEmpty()
                || $needles->contains(fn (string $keyword): bool => str_contains($lower, $keyword));
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

    private function publishedAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($value);

            return $date->gt(now()->addDay()) ? null : $date;
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeDocumentUrl(array $document): string
    {
        foreach (['canonical_url', 'final_url', 'requested_url'] as $key) {
            $candidate = trim((string) ($document[$key] ?? ''));
            if ($candidate === '') {
                continue;
            }

            try {
                return $this->urlGuard->inspect($candidate)['url'];
            } catch (\Throwable) {
                // Try the next browser-confirmed URL.
            }
        }

        return '';
    }
}
