<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\File;

class TelegramComponentPowerStore
{
    private string $path;

    public function __construct()
    {
        $this->path = storage_path('app/telegram/component-power.json');
    }

    public function section(string $section): array
    {
        $data = $this->all();
        $stored = is_array($data[$section] ?? null) ? $data[$section] : [];

        return [
            'bot_enabled' => array_key_exists('bot_enabled', $stored) ? (bool) $stored['bot_enabled'] : null,
            'disabled_account_ids' => array_values(array_unique(array_map('intval', (array) ($stored['disabled_account_ids'] ?? [])))),
            'disabled_api_ids' => array_values(array_unique(array_map('intval', (array) ($stored['disabled_api_ids'] ?? [])))),
        ];
    }

    public function botEnabled(string $section, bool $default): bool
    {
        $value = $this->section($section)['bot_enabled'];

        return $value === null ? $default : $value;
    }

    public function setBotEnabled(string $section, bool $enabled): void
    {
        $this->updateSection($section, ['bot_enabled' => $enabled]);
    }

    public function setComponentEnabled(string $section, string $type, int $id, bool $enabled): void
    {
        $key = $type === 'account' ? 'disabled_account_ids' : 'disabled_api_ids';
        $current = $this->section($section);
        $ids = $current[$key];

        if ($enabled) {
            $ids = array_values(array_filter($ids, fn (int $storedId): bool => $storedId !== $id));
        } elseif (! in_array($id, $ids, true)) {
            $ids[] = $id;
        }

        $this->updateSection($section, [$key => array_values($ids)]);
    }

    private function updateSection(string $section, array $changes): void
    {
        $data = $this->all();
        $data[$section] = array_merge(is_array($data[$section] ?? null) ? $data[$section] : [], $changes);
        $directory = dirname($this->path);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0770, true);
        }

        File::put($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true);
    }

    private function all(): array
    {
        if (! File::exists($this->path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) File::get($this->path), true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
