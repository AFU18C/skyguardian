<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class TelegramDeliveryUncertainException extends RuntimeException
{
    public function __construct(
        string $message = 'Telegram не подтвердил ответ. Сообщение могло быть опубликовано; автоматический повтор заблокирован.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
