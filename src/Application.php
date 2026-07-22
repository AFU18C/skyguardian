<?php
declare(strict_types=1);

namespace SkyGuardian;

final class Application
{
    public const VERSION = '1.0.0';

    public function health(): array
    {
        return [
            'ok' => true,
            'name' => 'SkyGuardian',
            'version' => self::VERSION,
        ];
    }
}
