<?php

namespace App\Services;

use danog\MadelineProto\RPCError\RateLimitError;
use Throwable;

class TelegramFloodWait
{
    public static function seconds(Throwable $exception): ?int
    {
        if ($exception instanceof RateLimitError) {
            return max(1, $exception->getWaitTimeLeft() ?: $exception->getWaitTime());
        }

        $message = $exception->getMessage();

        if (preg_match('/(?:FLOOD(?:_PREMIUM)?_WAIT|SLOWMODE_WAIT)_(\d+)/i', $message, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return null;
    }
}
