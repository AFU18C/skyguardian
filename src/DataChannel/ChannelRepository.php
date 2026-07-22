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
        if (!in_array($scope, ['news', 'alerts'], true)) throw new \InvalidArgumentException('Invalid scope.');
        return array_values(array_filter($this->store->read(self::STORE), static fn(array $item): bool => ($item['scope'] ?? null) === $scope));
    }

    public function save(array $channel): void
    {
        $id = (string) ($channel['id'] ?? '');
        $scope = (string) ($channel['scope'] ?? '');
        if ($id === '' || !in_array($scope, ['news', 'alerts'], true)) throw new \InvalidArgumentException('Invalid data channel.');
        $this->store->update(self::STORE, static function (array $items) use ($id, $scope, $channel): array {
            $scopeCount = count(array_filter($items, static fn(array $item): bool => ($item['scope'] ?? null) === $scope));
            if (!isset($items[$id]) && $scopeCount >= self::LIMIT) throw new \RuntimeException('Data channel limit reached.');
            $items[$id] = $channel;
            return $items;
        });
    }

    public function delete(string $id): void
    {
        $this->store->update(self::STORE, static function (array $items) use ($id): array {
            unset($items[$id]);
            return $items;
        });
    }
}
