<?php
declare(strict_types=1);

namespace SkyGuardian\Http;

final class RequestContext
{
    private static ?string $requestId = null;

    public static function initialize(?string $incoming = null): string
    {
        if (self::$requestId !== null) {
            return self::$requestId;
        }

        $incoming = trim((string) $incoming);
        self::$requestId = preg_match('/^[A-Za-z0-9._-]{8,128}$/', $incoming) === 1
            ? $incoming
            : bin2hex(random_bytes(16));

        if (!headers_sent()) {
            header('X-Request-ID: ' . self::$requestId);
        }

        return self::$requestId;
    }

    public static function id(): string
    {
        return self::initialize($_SERVER['HTTP_X_REQUEST_ID'] ?? null);
    }

    public static function reset(): void
    {
        self::$requestId = null;
    }
}
