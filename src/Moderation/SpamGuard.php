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
        $data = $this->store->read('anti_spam');
        $hits = array_values(array_filter($data[$key] ?? [], static fn ($time) => (int) $time > $now - $windowSeconds));
        $hits[] = $now;
        $data[$key] = $hits;
        $this->store->write('anti_spam', $data);
        return count($hits) > $limit;
    }
}
