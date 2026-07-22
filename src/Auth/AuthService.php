<?php
declare(strict_types=1);

namespace SkyGuardian\Auth;

final class AuthService
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly PasswordPolicy $passwordPolicy,
    ) {
    }

    public function createAdmin(string $email, string $password): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address.');
        }

        $this->passwordPolicy->validate($password);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            throw new \RuntimeException('Unable to hash password.');
        }

        $this->admins->save($email, $hash);
    }

    public function verify(string $email, string $password): bool
    {
        $admin = $this->admins->find();
        if ($admin === null) {
            return false;
        }

        return hash_equals((string) $admin['email'], mb_strtolower(trim($email)))
            && password_verify($password, (string) $admin['password_hash']);
    }
}
