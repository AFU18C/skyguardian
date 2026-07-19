<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WelcomeSettingsStore
{
    private const PATH = 'telegram/welcome-settings.json';

    public function get(): array
    {
        if (! Storage::disk('local')->exists(self::PATH)) {
            return $this->defaults();
        }

        $decoded = json_decode((string) Storage::disk('local')->get(self::PATH), true);

        return is_array($decoded)
            ? array_replace($this->defaults(), $decoded)
            : $this->defaults();
    }

    public function save(array $settings): array
    {
        $current = $this->get();
        $settings = array_replace($current, $settings);
        $settings['secret'] = filled($settings['secret'] ?? null)
            ? (string) $settings['secret']
            : Str::random(48);

        Storage::disk('local')->put(
            self::PATH,
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return $settings;
    }

    private function defaults(): array
    {
        return [
            'enabled' => false,
            'chat' => '',
            'bot' => 'news',
            'message' => 'Добро пожаловать, {name}! Рады видеть вас в группе {group}.',
            'secret' => '',
        ];
    }
}
