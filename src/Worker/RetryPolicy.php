<?php
declare(strict_types=1);

namespace SkyGuardian\Worker;

final class RetryPolicy
{
    public function __construct(
        private readonly int $maxAttempts = 4,
        private readonly int $baseDelayMilliseconds = 500,
        private readonly int $maxDelayMilliseconds = 30_000,
    ) {
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1.');
        }
    }

    /**
     * @template T
     * @param callable(int): T $operation Receives the current 1-based attempt number.
     * @param null|callable(int, \Throwable, int): void $onRetry Receives attempt, exception and delay in milliseconds.
     * @return T
     */
    public function run(callable $operation, ?callable $onRetry = null): mixed
    {
        $attempt = 1;

        while (true) {
            try {
                return $operation($attempt);
            } catch (\Throwable $exception) {
                if ($attempt >= $this->maxAttempts || !$this->isRetryable($exception)) {
                    throw $exception;
                }

                $delay = $this->delayMilliseconds($exception, $attempt);
                if ($onRetry !== null) {
                    $onRetry($attempt, $exception, $delay);
                }
                usleep($delay * 1000);
                $attempt++;
            }
        }
    }

    public function isRetryable(\Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());
        $class = mb_strtolower($exception::class);

        foreach ([
            'flood_wait', 'floodwait', 'timeout', 'timed out', 'temporarily unavailable',
            'connection reset', 'connection refused', 'network', 'rpc_call_fail',
            'server error', 'internal server error', 'bad gateway', 'gateway timeout',
        ] as $needle) {
            if (str_contains($message, $needle) || str_contains($class, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function delayMilliseconds(\Throwable $exception, int $attempt): int
    {
        $floodWaitSeconds = $this->floodWaitSeconds($exception);
        if ($floodWaitSeconds !== null) {
            return min($this->maxDelayMilliseconds, max(1, $floodWaitSeconds) * 1000);
        }

        $exponential = $this->baseDelayMilliseconds * (2 ** max(0, $attempt - 1));
        return min($this->maxDelayMilliseconds, $exponential);
    }

    public function floodWaitSeconds(\Throwable $exception): ?int
    {
        $message = $exception->getMessage();
        foreach ([
            '/FLOOD_WAIT[_\s-]?(\d+)/i',
            '/wait(?:ing)?\s+(\d+)\s+seconds?/i',
            '/retry\s+after\s+(\d+)/i',
        ] as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        foreach (['waitTime', 'seconds', 'retryAfter'] as $property) {
            if (property_exists($exception, $property) && is_numeric($exception->{$property})) {
                return (int) $exception->{$property};
            }
        }

        return null;
    }
}
