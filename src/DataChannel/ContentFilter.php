<?php
declare(strict_types=1);

namespace SkyGuardian\DataChannel;

final class ContentFilter
{
    public function accepts(string $text, array $keywords, array $stopWords): bool
    {
        $haystack = mb_strtolower($text);
        foreach ($stopWords as $word) {
            $word = trim(mb_strtolower((string) $word));
            if ($word !== '' && str_contains($haystack, $word)) {
                return false;
            }
        }
        $keywords = array_values(array_filter(array_map('trim', $keywords)));
        if ($keywords === []) {
            return true;
        }
        foreach ($keywords as $word) {
            if (str_contains($haystack, mb_strtolower($word))) {
                return true;
            }
        }
        return false;
    }

    public function format(string $text, array $channel): string
    {
        $body = trim($text);
        if (($channel['format'] ?? 'original') === 'text_without_links') {
            $body = trim((string) preg_replace('~https?://\S+~u', '', $body));
        }
        return trim((string) ($channel['before'] ?? '') . $body . (string) ($channel['after'] ?? ''));
    }
}