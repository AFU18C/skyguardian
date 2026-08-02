<?php

namespace App\Services;

use App\Models\GroupChannelBot;
use App\Models\GroupChannelPublication;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SkyGuardianPromoCampaignService
{
    private const CAMPAIGN_DIRECTORY = 'campaigns/skyguardian-start';

    private const STATUS_PATH = 'skyguardian-campaign-status.json';

    private const PALETTES = [
        [[5, 18, 38], [20, 102, 180], [255, 214, 10]],
        [[7, 24, 49], [16, 185, 129], [255, 214, 10]],
        [[16, 20, 48], [99, 102, 241], [255, 214, 10]],
        [[28, 15, 45], [217, 70, 239], [255, 214, 10]],
        [[37, 17, 17], [239, 68, 68], [59, 130, 246]],
        [[9, 31, 38], [6, 182, 212], [255, 214, 10]],
        [[23, 28, 19], [132, 204, 22], [59, 130, 246]],
        [[31, 20, 8], [245, 158, 11], [59, 130, 246]],
        [[15, 23, 42], [14, 165, 233], [250, 204, 21]],
        [[3, 22, 31], [34, 197, 94], [250, 204, 21]],
    ];

    public function launch(): int
    {
        $bot = GroupChannelBot::query()
            ->where('is_active', true)
            ->where('chat_type', 'channel')
            ->whereNotNull('chat_id')
            ->orderByDesc('last_manual_check_at')
            ->orderByDesc('id')
            ->first();

        if (! $bot) {
            throw new RuntimeException('Не найден активный Telegram-канал с заполненным Chat ID.');
        }

        $this->enablePublicationModules($bot);

        $posts = $this->posts();
        $startedAt = now();
        $created = 0;

        foreach ($posts as $post) {
            $number = (int) ($post['number'] ?? 0);
            $marker = sprintf('#SkyGuardianStart%02d', $number);

            if ($number < 1 || $number > 10 || empty($post['image']) || empty($post['text'])) {
                throw new RuntimeException('Некорректная конфигурация промо-кампании.');
            }

            if ($bot->publications()->where('text', 'like', '%'.$marker.'%')->exists()) {
                continue;
            }

            $storedPath = $this->storeImage($bot, (string) $post['image'], $number);

            $bot->publications()->create([
                'type' => GroupChannelPublication::TYPE_PHOTO,
                'text' => (string) $post['text'],
                'media_paths' => [[
                    'path' => $storedPath,
                    'name' => (string) $post['image'],
                    'mime' => 'image/png',
                ]],
                'buttons' => [[[
                    'text' => 'Відкрити SkyGuardian',
                    'url' => 'https://skyguardian.pp.ua/',
                ]]],
                'reactions' => [],
                'disable_notification' => false,
                'status' => GroupChannelPublication::STATUS_SCHEDULED,
                'scheduled_at' => $startedAt->copy()->addMinutes((int) $post['offset_minutes']),
            ]);

            $created++;
        }

        $this->writeStatus();

        return $created;
    }

    public function writeStatus(): void
    {
        $publications = GroupChannelPublication::query()
            ->with('bot')
            ->where('text', 'like', '%#SkyGuardianStart%')
            ->oldest('scheduled_at')
            ->get();

        $items = $publications
            ->map(function (GroupChannelPublication $publication): array {
                preg_match('/#SkyGuardianStart(\d{2})/u', $publication->text, $matches);
                $username = $this->publicChannelUsername($publication->bot?->group_link);

                return [
                    'number' => isset($matches[1]) ? (int) $matches[1] : null,
                    'scheduled_at' => $publication->scheduled_at?->toIso8601String(),
                    'status' => $publication->status,
                    'sent_at' => $publication->sent_at?->toIso8601String(),
                    'telegram_message_id' => $publication->telegram_message_id,
                    'post_link' => $username && $publication->telegram_message_id
                        ? 'https://t.me/'.$username.'/'.$publication->telegram_message_id
                        : null,
                    'last_error' => $publication->last_error,
                ];
            })
            ->sortBy('number')
            ->values()
            ->all();

        Storage::disk('public')->put(
            self::STATUS_PATH,
            json_encode([
                'campaign' => 'skyguardian-start',
                'generated_at' => now()->toIso8601String(),
                'posts' => $items,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    private function posts(): array
    {
        $path = resource_path(self::CAMPAIGN_DIRECTORY.'/posts.json');
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || count($decoded) !== 10) {
            throw new RuntimeException('Файл кампании должен содержать ровно 10 публикаций.');
        }

        return $decoded;
    }

    private function storeImage(GroupChannelBot $bot, string $filename, int $number): string
    {
        $storedPath = 'group-channel-publications/'.$bot->id.'/skyguardian-start/'.$filename;

        if (! Storage::disk('local')->put($storedPath, $this->generatePng($number))) {
            throw new RuntimeException('Не удалось сохранить изображение '.$filename.'.');
        }

        return $storedPath;
    }

    private function generatePng(int $number): string
    {
        $width = 512;
        $height = 512;
        [$background, $accent, $highlight] = self::PALETTES[$number - 1];
        $raw = '';
        $centerX = 256 + (($number % 3) - 1) * 18;
        $centerY = 240 + (($number % 4) - 2) * 12;

        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00";

            for ($x = 0; $x < $width; $x++) {
                $shade = (int) (($x + $y + ($number * 19)) / 18) % 24;
                $pixel = [
                    min(255, $background[0] + $shade),
                    min(255, $background[1] + $shade),
                    min(255, $background[2] + $shade),
                ];

                $dx = $x - $centerX;
                $dy = $y - $centerY;
                $distance = sqrt(($dx * $dx) + ($dy * $dy));

                foreach ([68, 132, 196] as $ring) {
                    if (abs($distance - $ring) < 2.2) {
                        $pixel = $accent;
                    }
                }

                if (abs($dx) < 2 || abs($dy) < 2) {
                    $pixel = $accent;
                }

                $angle = deg2rad(($number * 29) % 360);
                $lineDistance = abs(($dx * sin($angle)) - ($dy * cos($angle)));
                $lineProjection = ($dx * cos($angle)) + ($dy * sin($angle));

                if ($lineDistance < 2.5 && $lineProjection > 0 && $distance < 205) {
                    $pixel = $highlight;
                }

                if ($y >= 440 && $y < 464 && $x >= 52 && $x < 460) {
                    $pixel = $y < 452 ? [0, 87, 183] : [255, 215, 0];
                }

                $raw .= chr($pixel[0]).chr($pixel[1]).chr($pixel[2]);
            }
        }

        $signature = "\x89PNG\r\n\x1a\n";
        $header = pack('NNC5', $width, $height, 8, 2, 0, 0, 0);

        return $signature
            .$this->pngChunk('IHDR', $header)
            .$this->pngChunk('IDAT', gzcompress($raw, 9))
            .$this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            .$type
            .$data
            .pack('N', crc32($type.$data) & 0xFFFFFFFF);
    }

    private function enablePublicationModules(GroupChannelBot $bot): void
    {
        $settings = $bot->module_settings ?? [];
        data_set($settings, 'publications.enabled', true);
        data_set($settings, 'scheduled_publications.enabled', true);
        $bot->update(['module_settings' => $settings]);
    }

    private function publicChannelUsername(?string $link): ?string
    {
        if (! $link) {
            return null;
        }

        if (preg_match('/^@([A-Za-z0-9_]+)$/', trim($link), $matches)) {
            return $matches[1];
        }

        if (preg_match('~(?:https?://)?t\.me/([A-Za-z0-9_]+)(?:/|$)~i', trim($link), $matches)) {
            return $matches[1];
        }

        return null;
    }
}
