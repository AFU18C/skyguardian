<?php
declare(strict_types=1);

namespace SkyGuardian\Worker;

use SkyGuardian\Storage\JsonStore;

final class WorkerStatusRepository
{
    private const STORE = 'worker_status';

    public function __construct(private readonly JsonStore $store) {}

    public function get(string $scope): array
    {
        $items = $this->store->read(self::STORE);
        return $items[$scope] ?? [
            'status' => 'idle',
            'enabled' => false,
            'interval' => null,
            'next_check' => null,
            'last_check' => null,
            'last_publish' => null,
            'worker_seen' => null,
            'last_message_id' => null,
            'published_count' => 0,
            'last_error' => null,
            'initialized' => false,
        ];
    }

    public function save(string $scope, array $status): void
    {
        if (!in_array($scope, ['news', 'alerts'], true)) {
            throw new \InvalidArgumentException('Invalid worker scope.');
        }
        $items = $this->store->read(self::STORE);
        $items[$scope] = $status;
        $this->store->write(self::STORE, $items);
    }
}