<?php
declare(strict_types=1);

namespace SkyGuardian\Http;

final class Csrf
{
    public static function token(): string
    {
        SessionAuth::start();
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    public static function validate(?string $token): bool
    {
        SessionAuth::start();
        return is_string($token) && isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    public static function requireValid(?string $token): void
    {
        if (self::validate($token)) return;
        http_response_code(419);
        exit('CSRF validation failed');
    }
}
