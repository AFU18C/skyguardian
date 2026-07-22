<?php
declare(strict_types=1);

namespace SkyGuardian\Auth;

use SkyGuardian\Storage\JsonStore;

final class AdminRepository
{
    private const STORE = 'admin';

    public function __construct(private readonly JsonStore $store)
    {
    }

    public function find(): ?array
    {
        $admin = $this->store->read(self::STORE);

        return ($admin['email'] ?? '') !== '' && ($admin['password_hash'] ?? '') !== ''
            ? $admin
            : null;
    }

    public function save(string $email, string $passwordHash): void
    {
        $this->store->write(self::STORE, [
            'email' => mb_strtolower(trim($email)),
            'password_hash' => $passwordHash,
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }
}
