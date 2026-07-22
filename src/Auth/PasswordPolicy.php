<?php
declare(strict_types=1);

namespace SkyGuardian\Auth;

final class PasswordPolicy
{
    public function validate(string $password): void
    {
        $length = mb_strlen($password);
        if ($length < 8 || $length > 72) {
            throw new \InvalidArgumentException('Password must contain 8 to 72 characters.');
        }
    }
}
