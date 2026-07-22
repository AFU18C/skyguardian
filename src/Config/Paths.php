<?php
declare(strict_types=1);

namespace SkyGuardian\Config;

final class Paths
{
    public function __construct(private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function storage(): string
    {
        return $this->root . '/storage/v1';
    }

    public function ensureStorage(): void
    {
        $path = $this->storage();
        if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create storage directory.');
        }
        if (!is_writable($path)) {
            throw new \RuntimeException('Storage directory is not writable.');
        }
    }
}
