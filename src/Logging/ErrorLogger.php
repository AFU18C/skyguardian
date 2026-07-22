<?php
declare(strict_types=1);

namespace SkyGuardian\Logging;

use SkyGuardian\Http\RequestContext;

final class ErrorLogger
{
    public function __construct(private readonly string $storagePath)
    {
    }

    public function log(\Throwable $error, array $context = []): void
    {
        $directory = rtrim($this->storagePath, '/') . '/logs';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            return;
        }

        $record = [
            'timestamp' => gmdate(DATE_ATOM),
            'request_id' => RequestContext::id(),
            'type' => $error::class,
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'uri' => isset($_SERVER['REQUEST_URI']) ? strtok((string) $_SERVER['REQUEST_URI'], '?') : null,
            'context' => $this->sanitize($context),
        ];

        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($directory . '/app-' . gmdate('Y-m-d') . '.log', $json . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    private function sanitize(array $value): array
    {
        $sensitive = ['token', 'password', 'api_hash', 'secret', 'authorization', 'cookie'];
        foreach ($value as $key => $item) {
            $name = mb_strtolower((string) $key);
            if (array_filter($sensitive, static fn(string $needle): bool => str_contains($name, $needle))) {
                $value[$key] = '[redacted]';
            } elseif (is_array($item)) {
                $value[$key] = $this->sanitize($item);
            } elseif (is_string($item) && strlen($item) > 1000) {
                $value[$key] = substr($item, 0, 1000) . '…';
            }
        }
        return $value;
    }
}
