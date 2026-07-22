<?php
declare(strict_types=1);

namespace SkyGuardian\Storage;

final class JsonStore
{
    public function __construct(private readonly string $directory) {}

    public function read(string $name): array
    {
        $file = $this->file($name);
        if (!is_file($file)) return [];
        $handle = fopen($file, 'rb');
        if ($handle === false) throw new \RuntimeException('Unable to open storage file.');
        try {
            if (!flock($handle, LOCK_SH)) throw new \RuntimeException('Unable to lock storage file.');
            $raw = stream_get_contents($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    public function write(string $name, array $data): void
    {
        $this->update($name, static fn(array $current): array => $data);
    }

    public function update(string $name, callable $mutator): array
    {
        $file = $this->file($name);
        $lockFile = $file . '.lock';
        $lock = fopen($lockFile, 'c+');
        if ($lock === false) throw new \RuntimeException('Unable to open storage lock.');
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Unable to lock storage.');
            $current = [];
            if (is_file($file)) {
                $raw = file_get_contents($file);
                if ($raw === false) throw new \RuntimeException('Unable to read storage file.');
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $current = is_array($decoded) ? $decoded : [];
            }
            $next = $mutator($current);
            if (!is_array($next)) throw new \UnexpectedValueException('Storage mutator must return array.');
            $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $temp = tempnam($this->directory, '.json-');
            if ($temp === false) throw new \RuntimeException('Unable to create temporary storage file.');
            try {
                if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false) throw new \RuntimeException('Unable to write storage file.');
                chmod($temp, 0600);
                if (!rename($temp, $file)) throw new \RuntimeException('Unable to replace storage file.');
                chmod($file, 0600);
            } finally {
                if (is_file($temp)) @unlink($temp);
            }
            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @chmod($lockFile, 0600);
        }
    }

    private function file(string $name): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $name)) throw new \InvalidArgumentException('Invalid storage name.');
        if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) throw new \RuntimeException('Unable to create storage directory.');
        return $this->directory . '/' . $name . '.json';
    }
}
