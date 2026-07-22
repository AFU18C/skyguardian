<?php
declare(strict_types=1);

namespace SkyGuardian\Telegram;

use SkyGuardian\Storage\JsonStore;

final class AccountRepository
{
    private const STORE = 'telegram_accounts';

    public function __construct(private readonly JsonStore $store) {}

    public function all(): array
    {
        return array_values($this->store->read(self::STORE));
    }

    public function save(array $account): void
    {
        $id = (string) ($account['id'] ?? '');
        if ($id === '') {
            throw new \InvalidArgumentException('Account id is required.');
        }
        $items = $this->store->read(self::STORE);
        $items[$id] = $account;
        $this->store->write(self::STORE, $items);
    }

    public function delete(string $id): void
    {
        $items = $this->store->read(self::STORE);
        unset($items[$id]);
        $this->store->write(self::STORE, $items);
    }
}