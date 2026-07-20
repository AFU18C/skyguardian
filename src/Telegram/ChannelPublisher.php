<?php

declare(strict_types=1);

namespace SkyGuardian\Telegram;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use RuntimeException;
use Throwable;

final class ChannelPublisher
{
    private API $client;

    public function __construct(string $sessionPath, int $apiId, string $apiHash)
    {
        $settings = new Settings();
        $settings->getAppInfo()->setApiId($apiId)->setApiHash($apiHash);
        $this->client = new API($sessionPath, $settings);
    }

    public function isConnected(): bool
    {
        try {
            return $this->client->getAuthorization() === API::LOGGED_IN;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getNewMessages(string $source, int $afterId, int $limit = 20): array
    {
        if (!$this->isConnected()) {
            throw new RuntimeException('Технический аккаунт не подключён к Telegram.');
        }

        $history = $this->client->messages->getHistory(
            peer: $this->normalizePeer($source),
            min_id: max(0, $afterId),
            limit: max(1, min(100, $limit)),
        );

        $messages = array_values(array_filter(
            $history['messages'] ?? [],
            static fn (mixed $message): bool => is_array($message)
                && isset($message['id'])
                && ($message['_'] ?? '') !== 'messageEmpty',
        ));

        usort($messages, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));
        return $messages;
    }

    public function publish(array $message, string $source, string $destination, string $mode, string $footerHtml = ''): void
    {
        $source = $this->normalizePeer($source);
        $destination = $this->normalizePeer($destination);
        $messageId = (int) ($message['id'] ?? 0);
        if ($messageId <= 0) {
            throw new RuntimeException('Telegram вернул сообщение без корректного ID.');
        }

        if ($mode === 'forward_original') {
            $this->client->messages->forwardMessages(
                from_peer: $source,
                id: [$messageId],
                to_peer: $destination,
            );
            return;
        }

        $text = trim((string) ($message['message'] ?? ''));
        if ($mode === 'clean_copy') {
            $text = preg_replace('~(?:https?://\S+|t\.me/\S+|@[\pL\pN_]+)~u', '', $text) ?? $text;
            $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
        }

        $footer = trim(strip_tags($footerHtml, '<b><strong><i><em><u><s><a><ul><ol><li><br>'));
        if ($footer !== '') {
            $text = trim($text . "\n\n" . $footer);
        }

        $hasMedia = isset($message['media']) && is_array($message['media']) && ($message['media']['_'] ?? '') !== 'messageMediaEmpty';
        if ($mode === 'media_only' && !$hasMedia) {
            return;
        }
        if ($mode === 'text_only') {
            if ($text !== '') {
                $this->client->messages->sendMessage(peer: $destination, message: $text, parse_mode: 'HTML');
            }
            return;
        }

        if ($hasMedia) {
            $this->client->messages->sendMedia(
                peer: $destination,
                media: $message['media'],
                message: $mode === 'media_only' ? '' : $text,
                parse_mode: 'HTML',
            );
            return;
        }

        if ($text !== '') {
            $this->client->messages->sendMessage(peer: $destination, message: $text, parse_mode: 'HTML');
        }
    }

    private function normalizePeer(string $peer): string
    {
        $peer = trim($peer);
        if (preg_match('~^https?://t\.me/(?:s/)?([^/?#]+)~i', $peer, $match)) {
            return '@' . ltrim($match[1], '@');
        }
        return $peer;
    }
}
