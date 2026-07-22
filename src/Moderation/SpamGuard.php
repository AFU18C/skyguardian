<?php
declare(strict_types=1);

namespace SkyGuardian\Moderation;

use SkyGuardian\Storage\JsonStore;

final class SpamGuard
{
    public function __construct(private readonly JsonStore $store) {}

    public function isSpam(string $chatId, int $userId, int $limit = 5, int $windowSeconds = 10): bool
    {
        $now = time();
        $key = $chatId . ':' . $userId;
        $spam = false;
        $this->store->update('anti_spam', static function (array $data) use ($now, $key, $limit, $windowSeconds, &$spam): array {
            $hits = array_values(array_filter((array) ($data[$key] ?? []), static fn($time): bool => (int) $time > $now - $windowSeconds));
            $hits[] = $now;
            $data[$key] = $hits;
            $spam = count($hits) > $limit;
            return $data;
        });
        return $spam;
    }
}
