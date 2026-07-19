<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\File;
use RuntimeException;

class TelegramApiPowerStore
{
    public function enabled(string $section, int $id): bool
    {
        $data = $this->read();

        return (bool) data_get($data, $section.'.'.$id, true);
    }

    public function set(string $section, int $id, bool $enabled): void
    {
        $data = $this->read();
        data_set($data, $section.'.'.$id, $enabled);
        $this->write($data);
    }

    private function path(): string
    {
        return storage_path('app/private/telegram/api-power.json');
    }

    private function read(): array
    {
        $path = $this->path();
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function write(array $data): void
    {
        $path = $this->path();
        File::ensureDirectoryExists(dirname($path), 0770, true);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (File::put($path, $json."\n", true) === false) {
            throw new RuntimeException('Не удалось сохранить состояние Telegram API.');
        }
    }
}
