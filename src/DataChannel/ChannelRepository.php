<?php
declare(strict_types=1);

namespace SkyGuardian\DataChannel;

use SkyGuardian\Storage\JsonStore;

final class ChannelRepository
{
    private const STORE = 'data_channels';
    private const LIMIT = 10;

    public function __construct(private readonly JsonStore $store) {}

    public function all(string $scope): array
    {
        return array_values(array_filter(
            $this->store->read(self::STORE),
            static fn(array $item): bool => ($item['scope'] ?? null) === $scope
        ));
    }

    public function save(array $channel): void
    {
        $id = (string) ($channel['id'] ?? '');
        $scope = (string) ($channel['scope'] ?? '');
        if ($id === '' || !in_array($scope, ['news', 'alerts'], true)) {
            throw new \InvalidArgumentException('Invalid data channel.');
        }

        $items = $this->store->read(self::STORE);
        if (!isset($items[$id]) && count($items) >= self::LIMIT) {
            throw new \RuntimeException('Data channel limit reached.');
        }
        $items[$id] = $channel;
        $this->store->write(self::STORE, $items);
    }

    public function delete(string $id): void
    {
        $items = $this->store->read(self::STORE);
        unset($items[$id]);
        $this->store->write(self::STORE, $items);
    }
}