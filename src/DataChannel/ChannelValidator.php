<?php
declare(strict_types=1);

namespace SkyGuardian\DataChannel;

final class ChannelValidator
{
    private const FORMATS = ['original', 'text', 'text_without_links', 'media', 'text_and_media'];
    private const FETCH_START = ['new', 'last_5', 'last_10', 'last_20'];

    public function validate(array $channel): array
    {
        foreach (['id', 'scope', 'source', 'destination', 'account_id'] as $field) {
            if (trim((string) ($channel[$field] ?? '')) === '') {
                throw new \InvalidArgumentException($field . ' is required.');
            }
        }
        if (!in_array($channel['scope'], ['news', 'alerts'], true)) {
            throw new \InvalidArgumentException('Invalid scope.');
        }
        if (!in_array($channel['format'] ?? 'original', self::FORMATS, true)) {
            throw new \InvalidArgumentException('Invalid publication format.');
        }
        if (!in_array($channel['fetch_start'] ?? 'new', self::FETCH_START, true)) {
            throw new \InvalidArgumentException('Invalid fetch start.');
        }
        $channel['enabled'] = (bool) ($channel['enabled'] ?? false);
        $channel['frequency'] = max(1, (int) ($channel['frequency'] ?? 1));
        $channel['frequency_unit'] = in_array($channel['frequency_unit'] ?? 'minutes', ['minutes', 'hours'], true)
            ? $channel['frequency_unit'] : 'minutes';
        return $channel;
    }
}