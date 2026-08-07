<?php

namespace App\Services;

/**
 * Прямой Telegram Bot API клиент для внутренних публикаций, которые уже
 * самостоятельно управляют жизненным циклом message_id.
 *
 * В отличие от GroupedAlertTelegramService этот сервис не перехватывает
 * sendMessage и не превращает его в editMessageText.
 */
class DirectGroupChannelTelegramService extends GroupChannelTelegramService
{
    // Намеренно без дополнительной логики.
}
