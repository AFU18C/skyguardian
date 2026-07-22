<?php
declare(strict_types=1);

namespace SkyGuardian\Moderation;

final class MessageInspector
{
    public function inspect(string $text, array $settings): ?string
    {
        $normalized = mb_strtolower($text);
        if (($settings['link_filter'] ?? false) && preg_match('~(?:https?://|t\.me/|www\.)~iu', $text)) {
            return 'link';
        }
        foreach (($settings['forbidden_words'] ?? []) as $word) {
            $word = mb_strtolower(trim((string) $word));
            if ($word !== '' && str_contains($normalized, $word)) {
                return 'forbidden_word';
            }
        }
        return null;
    }
}
