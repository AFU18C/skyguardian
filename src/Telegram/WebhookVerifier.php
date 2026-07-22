<?php
declare(strict_types=1);

namespace SkyGuardian\Telegram;

final class WebhookVerifier
{
    public function verify(string $urlSecret, ?string $headerSecret, string $expected): bool
    {
        if ($expected === '') {
            return false;
        }
        return hash_equals($expected, $urlSecret) && $headerSecret !== null && hash_equals($expected, $headerSecret);
    }
}
