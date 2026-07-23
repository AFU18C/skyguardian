<?php
declare(strict_types=1);

final class TelegramRuntimeLock
{
    /** @var resource */
    private $handle;

    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    public static function acquire(string $storageDir, bool $nonBlocking = false): self
    {
        $storageDir = rtrim($storageDir, '/');
        if (!is_dir($storageDir) && !@mkdir($storageDir, 0700, true) && !is_dir($storageDir)) {
            throw new RuntimeException('Cannot create Telegram runtime storage directory.');
        }

        $lockFile = $storageDir . '/telegram-automation-state.lock';
        $handle = fopen($lockFile, 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open Telegram runtime lock file.');
        }
        @chmod($lockFile, 0600);

        $operation = LOCK_EX | ($nonBlocking ? LOCK_NB : 0);
        if (!flock($handle, $operation)) {
            fclose($handle);
            throw new RuntimeException('Telegram runtime is already processing state.');
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
