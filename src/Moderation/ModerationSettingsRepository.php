<?php
declare(strict_types=1);

namespace SkyGuardian\Moderation;

use SkyGuardian\Storage\JsonStore;

final class ModerationSettingsRepository
{
    public function __construct(private readonly JsonStore $store) {}

    public function get(): array
    {
        return array_replace([
            'anti_spam' => false,
            'link_filter' => false,
            'forbidden_words' => [],
            'mute_seconds' => 0,
            'admin_bypass' => true,
        ], $this->store->read('moderation'));
    }

    public function save(array $settings): void
    {
        $this->store->write('moderation', $settings);
    }
}
