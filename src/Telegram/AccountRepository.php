<?php
declare(strict_types=1);

namespace SkyGuardian\Telegram;

use SkyGuardian\Storage\JsonStore;

final class AccountRepository
{
    private const STORE = 'telegram_accounts';
    public function __construct(private readonly JsonStore $store) {}

    public function all(): array { return array_values($this->store->read(self::STORE)); }

    public function save(array $account): void
    {
        $id = (string) ($account['id'] ?? '');
        if ($id === '') throw new \InvalidArgumentException('Account id is required.');
        $this->store->update(self::STORE, static function (array $items) use ($id, $account): array {
            $items[$id] = array_replace($items[$id] ?? [], $account);
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
