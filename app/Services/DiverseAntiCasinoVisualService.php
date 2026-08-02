<?php

namespace App\Services;

use App\Models\GroupChannelPublication;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DiverseAntiCasinoVisualService
{
    public const DESIGN_COUNT = 300;

    public const VISUAL_VERSION = 3;

    private const WIDTH = 256;

    private const HEIGHT = 256;

    private const STATUS_PATH = 'anti-casino-visual-refresh.json';

    private const PALETTE = [
        [0, 0, 0],
        [8, 20, 38],
        [31, 20, 58],
        [55, 16, 29],
        [7, 54, 59],
        [15, 76, 129],
        [91, 46, 145],
        [101, 55, 19],
        [255, 202, 40],
        [22, 22, 26],
        [82, 88, 98],
        [207, 214, 224],
        [247, 249, 252],
        [23, 160, 95],
        [255, 102, 70],
        [220, 30, 55],
    ];

    private const FONT = [
        'A' => ['01110', '10001', '10001', '11111', '10001', '10001', '10001'],
        'C' => ['01111', '10000', '10000', '10000', '10000', '10000', '01111'],
        'I' => ['11111', '00100', '00100', '00100', '00100', '00100', '11111'],
        'N' => ['10001', '11001', '10101', '10011', '10001', '10001', '10001'],
        'O' => ['01110', '10001', '10001', '10001', '10001', '10001', '01110'],
        'S' => ['01111', '10000', '10000', '01110', '00001', '00001', '11110'],
    ];

    public function refreshScheduled(): int
    {
        $refreshed = 0;

        GroupChannelPublication::query()
            ->where('status', GroupChannelPublication::STATUS_SCHEDULED)
            ->where('text', 'like', '%#AntiCasino1000%')
            ->orderBy('id')
            ->chunkById(100, function ($publications) use (&$refreshed): void {
                foreach ($publications as $publication) {
                    if (! preg_match('/#AC(\d{4})/u', $publication->text, $matches)) {
                        continue;
                    }

                    $media = $publication->media_paths[0] ?? null;
                    $path = is_array($media) ? ($media['path'] ?? null) : null;

                    if (! is_string($path) || $path === '') {
                        continue;
                    }

                    $index = (int) $matches[1];

                    if (! Storage::disk('local')->put($path, $this->generatePng($index))) {
                        throw new RuntimeException('Не удалось обновить изображение публикации '.$publication->id.'.');
                    }

                    $refreshed++;
                }
            });

        Storage::disk('public')->put(
            self::STATUS_PATH,
            json_encode([
                'visual_version' => self::VISUAL_VERSION,
                'design_count' => self::DESIGN_COUNT,
                'refreshed_scheduled_posts' => $refreshed,
                'generated_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return $refreshed;
    }

    public function generatePng(int $postIndex): string
    {
        $style = (($postIndex - 1) % self::DESIGN_COUNT) + 1;
        $scene = intdiv($style - 1, 10);
        $layout = ($style - 1) % 10;
        $base = 1 + (($scene + $layout) % 7);
        $accent = 8 + (($scene * 3 + $layout) % 8);
        $secondary = 5 + (($scene + $layout * 2) % 3);
        $pixels = str_repeat(chr($base), self::WIDTH * self::HEIGHT);

        $this->drawBackground($pixels, $layout, $base, $secondary, $accent);
        [$cx, $cy, $scale, $titleY, $titleScale] = $this->layout($layout);
        $this->drawScene($pixels, $scene, $cx, $cy, $scale, $accent, $secondary);

        $this->drawCenteredText($pixels, 'NO CASINO', $titleY, $titleScale, 12);
        $this->drawNumber($pixels, $style, 3, 12, 12, 2, 8);
        $this->drawNumber($pixels, $postIndex, 4, 174, 232, 2, 12);

        if (in_array($layout, [0, 3, 7, 9], true)) {
            $this->drawLine($pixels, 22, 222, 234, 30, 15, 6);
        }

        return $this->encodePng($pixels);
    }

    private function layout(int $layout): array
    {
        return match ($layout) {
            0 => [128, 139, 1.0, 34, 2],
            1 => [86, 144, 0.82, 30, 2],
            2 => [174, 144, 0.82, 30, 2],
            3 => [128, 164, 0.78, 48, 2],
            4 => [128, 112, 0.78, 202, 2],
            5 => [75, 105, 0.64, 196, 2],
            6 => [181, 181, 0.64, 34, 2],
            7 => [181, 103, 0.64, 204, 2],
            8 => [76, 181, 0.64, 34, 2],
            default => [128, 140, 0.9, 30, 2],
        };
    }

    private function drawBackground(string &$pixels, int $layout, int $base, int $secondary, int $accent): void
    {
        switch ($layout) {
            case 0:
                $this->drawRect($pixels, 0, 0, 255, 25, 9);
                $this->drawRect($pixels, 0, 224, 255, 255, $secondary);
                $this->drawRect($pixels, 12, 38, 243, 216, $base);
                $this->drawFrame($pixels, 12, 38, 243, 216, $accent, 3);
                break;
            case 1:
                $this->drawRect($pixels, 128, 0, 255, 255, $secondary);
                $this->drawRect($pixels, 116, 0, 127, 255, $accent);
                $this->drawCircle($pixels, 197, 58, 38, 9);
                break;
            case 2:
                $this->drawRect($pixels, 0, 0, 127, 255, $secondary);
                $this->drawRect($pixels, 128, 0, 139, 255, $accent);
                $this->drawCircle($pixels, 57, 199, 42, 9);
                break;
            case 3:
                for ($offset = -220; $offset < 300; $offset += 28) {
                    $this->drawLine($pixels, $offset, 256, $offset + 160, 0, $secondary, 10);
                }
                $this->drawRect($pixels, 0, 0, 255, 36, 9);
                break;
            case 4:
                $this->drawRect($pixels, 0, 0, 255, 70, $secondary);
                $this->drawRect($pixels, 0, 188, 255, 255, 9);
                $this->drawCircle($pixels, 128, 124, 92, $accent);
                $this->drawCircle($pixels, 128, 124, 78, $base);
                break;
            case 5:
                $this->drawRect($pixels, 10, 12, 118, 242, 12);
                $this->drawRect($pixels, 138, 12, 246, 242, 11);
                for ($y = 42; $y < 228; $y += 16) {
                    $this->drawRect($pixels, 20, $y, 105, $y + 3, 10);
                    $this->drawRect($pixels, 150, $y, 235, $y + 3, 10);
                }
                $this->drawRect($pixels, 122, 0, 134, 255, $accent);
                break;
            case 6:
                $this->drawRect($pixels, 25, 35, 205, 212, 10);
                $this->drawRect($pixels, 39, 25, 219, 202, 11);
                $this->drawRect($pixels, 53, 15, 233, 192, 12);
                $this->drawFrame($pixels, 53, 15, 233, 192, $accent, 4);
                break;
            case 7:
                $this->drawRect($pixels, 0, 0, 255, 255, 9);
                for ($offset = -180; $offset < 320; $offset += 42) {
                    $this->drawLine($pixels, $offset, 256, $offset + 150, 0, 8, 20);
                    $this->drawLine($pixels, $offset + 12, 256, $offset + 162, 0, 15, 8);
                }
                $this->drawRect($pixels, 38, 40, 218, 216, $base);
                break;
            case 8:
                $this->drawRect($pixels, 0, 0, 88, 88, $secondary);
                $this->drawRect($pixels, 168, 0, 255, 88, $accent);
                $this->drawRect($pixels, 0, 168, 88, 255, $accent);
                $this->drawRect($pixels, 168, 168, 255, 255, $secondary);
                $this->drawRect($pixels, 72, 72, 184, 184, 9);
                $this->drawFrame($pixels, 72, 72, 184, 184, 12, 3);
                break;
            default:
                for ($x = 0; $x <= 256; $x += 24) {
                    $this->drawLine($pixels, 128, 128, $x, 0, $secondary, 5);
                    $this->drawLine($pixels, 128, 128, $x, 255, $accent, 4);
                }
                for ($y = 0; $y <= 256; $y += 24) {
                    $this->drawLine($pixels, 128, 128, 0, $y, $accent, 4);
                    $this->drawLine($pixels, 128, 128, 255, $y, $secondary, 5);
                }
                $this->drawCircle($pixels, 128, 128, 72, $base);
                break;
        }
    }

    private function drawScene(string &$pixels, int $scene, int $cx, int $cy, float $scale, int $accent, int $secondary): void
    {
        $x = fn (float $value): int => $cx + (int) round($value * $scale);
        $y = fn (float $value): int => $cy + (int) round($value * $scale);
        $r = fn (float $value): int => max(1, (int) round($value * $scale));

        switch ($scene) {
            case 0: // slot machine
                $this->drawRect($pixels, $x(-48), $y(-48), $x(48), $y(48), 10);
                $this->drawFrame($pixels, $x(-48), $y(-48), $x(48), $y(48), $accent, $r(4));
                for ($i = -1; $i <= 1; $i++) {
                    $left = $x(($i * 30) - 12);
                    $this->drawRect($pixels, $left, $y(-20), $left + $r(24), $y(18), 12);
                    $this->drawCircle($pixels, $left + $r(12), $y(0), $r(8), 8 + (($i + 3) % 3));
                }
                $this->drawLine($pixels, $x(50), $y(-25), $x(70), $y(-45), $accent, $r(5));
                $this->drawCircle($pixels, $x(72), $y(-48), $r(7), 15);
                break;
            case 1: // broken piggy bank
                $this->drawCircle($pixels, $x(0), $y(0), $r(42), 14);
                $this->drawRect($pixels, $x(25), $y(-8), $x(54), $y(13), 14);
                $this->drawCircle($pixels, $x(52), $y(2), $r(8), 12);
                $this->drawLine($pixels, $x(-5), $y(-34), $x(7), $y(-8), 9, $r(4));
                $this->drawLine($pixels, $x(7), $y(-8), $x(-7), $y(12), 9, $r(4));
                $this->drawLine($pixels, $x(-7), $y(12), $x(8), $y(34), 9, $r(4));
                $this->drawCircle($pixels, $x(-40), $y(-48), $r(10), 8);
                break;
            case 2: // empty wallet
                $this->drawRect($pixels, $x(-55), $y(-36), $x(55), $y(36), 7);
                $this->drawFrame($pixels, $x(-55), $y(-36), $x(55), $y(36), 8, $r(4));
                $this->drawRect($pixels, $x(8), $y(-16), $x(60), $y(16), 10);
                $this->drawCircle($pixels, $x(42), $y(0), $r(5), 15);
                $this->drawLine($pixels, $x(-40), $y(20), $x(-10), $y(-20), 15, $r(5));
                break;
            case 3: // news card and casino chip
                $this->drawRect($pixels, $x(-58), $y(-46), $x(24), $y(46), 12);
                $this->drawRect($pixels, $x(-46), $y(-30), $x(12), $y(-22), 10);
                for ($row = -10; $row <= 28; $row += 13) {
                    $this->drawRect($pixels, $x(-46), $y($row), $x(8), $y($row + 4), 11);
                }
                $this->drawCircle($pixels, $x(42), $y(4), $r(34), $accent);
                $this->drawCircle($pixels, $x(42), $y(4), $r(21), 9);
                $this->drawDigit($pixels, $x(36), $y(-8), 7, 8, $r(4));
                break;
            case 4: // megaphone throwing coins
                $this->drawTriangle($pixels, $x(-52), $y(-24), $x(18), $y(-48), $x(18), $y(24), $accent);
                $this->drawRect($pixels, $x(-62), $y(-18), $x(-48), $y(18), 10);
                $this->drawLine($pixels, $x(-20), $y(20), $x(-5), $y(48), 10, $r(7));
                for ($i = 0; $i < 4; $i++) {
                    $this->drawCircle($pixels, $x(38 + $i * 14), $y(-30 + $i * 17), $r(7), 8);
                }
                break;
            case 5: // funnel
                $this->drawTriangle($pixels, $x(-58), $y(-48), $x(58), $y(-48), $x(14), $y(18), $secondary);
                $this->drawRect($pixels, $x(-14), $y(16), $x(14), $y(54), $accent);
                for ($i = -2; $i <= 2; $i++) {
                    $this->drawCircle($pixels, $x($i * 22), $y(-62 - abs($i) * 3), $r(8), 8);
                }
                $this->drawCircle($pixels, $x(0), $y(65), $r(10), 15);
                break;
            case 6: // scales
                $this->drawLine($pixels, $x(0), $y(-55), $x(0), $y(55), 12, $r(5));
                $this->drawLine($pixels, $x(-55), $y(-28), $x(55), $y(-28), 12, $r(5));
                $this->drawLine($pixels, $x(-40), $y(-28), $x(-54), $y(18), 10, $r(3));
                $this->drawLine($pixels, $x(40), $y(-28), $x(54), $y(18), 10, $r(3));
                $this->drawTriangle($pixels, $x(-70), $y(20), $x(-38), $y(20), $x(-54), $y(39), 8);
                $this->drawTriangle($pixels, $x(38), $y(20), $x(70), $y(20), $x(54), $y(39), 15);
                break;
            case 7: // shield
                $this->drawPolygon($pixels, [[$x(0), $y(-58)], [$x(48), $y(-38)], [$x(38), $y(28)], [$x(0), $y(58)], [$x(-38), $y(28)], [$x(-48), $y(-38)]], 13);
                $this->drawLine($pixels, $x(-24), $y(0), $x(-6), $y(20), 12, $r(7));
                $this->drawLine($pixels, $x(-6), $y(20), $x(29), $y(-22), 12, $r(7));
                break;
            case 8: // warning triangle
                $this->drawTriangle($pixels, $x(0), $y(-62), $x(-64), $y(52), $x(64), $y(52), 8);
                $this->drawTriangle($pixels, $x(0), $y(-45), $x(-46), $y(38), $x(46), $y(38), 9);
                $this->drawRect($pixels, $x(-6), $y(-20), $x(6), $y(16), 15);
                $this->drawCircle($pixels, $x(0), $y(29), $r(7), 15);
                break;
            case 9: // phone ad
                $this->drawRect($pixels, $x(-38), $y(-66), $x(38), $y(66), 10);
                $this->drawFrame($pixels, $x(-38), $y(-66), $x(38), $y(66), 12, $r(4));
                $this->drawRect($pixels, $x(-27), $y(-42), $x(27), $y(28), $accent);
                $this->drawCircle($pixels, $x(0), $y(-8), $r(18), 8);
                $this->drawRect($pixels, $x(-18), $y(38), $x(18), $y(48), 13);
                break;
            case 10: // hand and coin
                $this->drawRect($pixels, $x(-58), $y(10), $x(20), $y(35), 11);
                $this->drawCircle($pixels, $x(28), $y(20), $r(20), 11);
                $this->drawCircle($pixels, $x(10), $y(-42), $r(20), 8);
                $this->drawLine($pixels, $x(10), $y(-20), $x(10), $y(2), 15, $r(3));
                $this->drawTriangle($pixels, $x(10), $y(8), $x(-2), $y(-2), $x(22), $y(-2), 15);
                break;
            case 11: // roulette
                $this->drawCircle($pixels, $x(0), $y(0), $r(60), 15);
                $this->drawCircle($pixels, $x(0), $y(0), $r(49), 9);
                $this->drawCircle($pixels, $x(0), $y(0), $r(18), 8);
                for ($angle = 0; $angle < 360; $angle += 30) {
                    $rad = deg2rad($angle);
                    $this->drawLine($pixels, $x(18 * cos($rad)), $y(18 * sin($rad)), $x(49 * cos($rad)), $y(49 * sin($rad)), $accent, $r(3));
                }
                $this->drawCircle($pixels, $x(30), $y(-34), $r(7), 12);
                break;
            case 12: // dice
                $this->drawRect($pixels, $x(-58), $y(-28), $x(2), $y(32), 12);
                $this->drawRect($pixels, $x(8), $y(-42), $x(62), $y(12), 11);
                foreach ([[-42, -12], [-28, 16], [-10, -12], [24, -25], [48, -25], [36, -2]] as [$dx, $dy]) {
                    $this->drawCircle($pixels, $x($dx), $y($dy), $r(5), 9);
                }
                break;
            case 13: // playing cards
                $this->drawRect($pixels, $x(-52), $y(-54), $x(12), $y(50), 12);
                $this->drawFrame($pixels, $x(-52), $y(-54), $x(12), $y(50), 15, $r(3));
                $this->drawRect($pixels, $x(-6), $y(-42), $x(58), $y(62), 11);
                $this->drawFrame($pixels, $x(-6), $y(-42), $x(58), $y(62), $accent, $r(3));
                $this->drawCircle($pixels, $x(-20), $y(-15), $r(12), 15);
                $this->drawTriangle($pixels, $x(26), $y(-15), $x(10), $y(14), $x(42), $y(14), $accent);
                break;
            case 14: // receipt
                $this->drawRect($pixels, $x(-45), $y(-62), $x(45), $y(58), 12);
                for ($row = -42; $row <= 22; $row += 16) {
                    $this->drawRect($pixels, $x(-30), $y($row), $x(28), $y($row + 4), 10);
                }
                $this->drawLine($pixels, $x(-28), $y(40), $x(28), $y(40), 15, $r(6));
                $this->drawTriangle($pixels, $x(-45), $y(58), $x(-30), $y(70), $x(-15), $y(58), 12);
                $this->drawTriangle($pixels, $x(-15), $y(58), $x(0), $y(70), $x(15), $y(58), 12);
                $this->drawTriangle($pixels, $x(15), $y(58), $x(30), $y(70), $x(45), $y(58), 12);
                break;
            case 15: // cracked coin
                $this->drawCircle($pixels, $x(0), $y(0), $r(58), 8);
                $this->drawCircle($pixels, $x(0), $y(0), $r(43), $accent);
                $this->drawLine($pixels, $x(-10), $y(-52), $x(6), $y(-18), 9, $r(5));
                $this->drawLine($pixels, $x(6), $y(-18), $x(-8), $y(8), 9, $r(5));
                $this->drawLine($pixels, $x(-8), $y(8), $x(12), $y(50), 9, $r(5));
                break;
            case 16: // house and debt
                $this->drawTriangle($pixels, $x(0), $y(-66), $x(-68), $y(-8), $x(68), $y(-8), 15);
                $this->drawRect($pixels, $x(-52), $y(-8), $x(52), $y(60), 11);
                $this->drawRect($pixels, $x(-14), $y(16), $x(14), $y(60), 9);
                $this->drawLine($pixels, $x(-52), $y(4), $x(54), $y(42), $accent, $r(6));
                break;
            case 17: // worker helmet and wallet
                $this->drawCircle($pixels, $x(-22), $y(-10), $r(39), 8);
                $this->drawRect($pixels, $x(-66), $y(-12), $x(20), $y(4), 8);
                $this->drawRect($pixels, $x(4), $y(10), $x(66), $y(58), 7);
                $this->drawFrame($pixels, $x(4), $y(10), $x(66), $y(58), 15, $r(4));
                $this->drawLine($pixels, $x(18), $y(22), $x(52), $y(48), 15, $r(5));
                break;
            case 18: // siren
                $this->drawRect($pixels, $x(-50), $y(38), $x(50), $y(58), 10);
                $this->drawCircle($pixels, $x(0), $y(10), $r(43), 15);
                $this->drawRect($pixels, $x(-42), $y(10), $x(42), $y(42), 15);
                for ($angle = 0; $angle < 360; $angle += 45) {
                    $rad = deg2rad($angle);
                    $this->drawLine($pixels, $x(55 * cos($rad)), $y(-15 + 55 * sin($rad)), $x(75 * cos($rad)), $y(-15 + 75 * sin($rad)), 8, $r(5));
                }
                break;
            case 19: // magnifier over ad
                $this->drawRect($pixels, $x(-58), $y(-46), $x(26), $y(38), 12);
                $this->drawCircle($pixels, $x(-16), $y(-6), $r(22), $accent);
                $this->drawCircle($pixels, $x(22), $y(10), $r(40), 11);
                $this->drawCircle($pixels, $x(22), $y(10), $r(29), 9);
                $this->drawLine($pixels, $x(50), $y(38), $x(72), $y(62), 11, $r(9));
                break;
            case 20: // hook catching wallet
                $this->drawLine($pixels, $x(-15), $y(-68), $x(-15), $y(18), 11, $r(4));
                $this->drawLine($pixels, $x(-15), $y(18), $x(12), $y(42), 11, $r(4));
                $this->drawCircle($pixels, $x(19), $y(32), $r(15), 11);
                $this->drawRect($pixels, $x(20), $y(20), $x(70), $y(62), 7);
                $this->drawFrame($pixels, $x(20), $y(20), $x(70), $y(62), 15, $r(4));
                break;
            case 21: // chain
                for ($i = -2; $i <= 2; $i++) {
                    $this->drawCircleOutline($pixels, $x($i * 26), $y(($i % 2) * 12), $r(19), 11, $r(6));
                }
                $this->drawLine($pixels, $x(-68), $y(-50), $x(68), $y(50), 15, $r(7));
                break;
            case 22: // black hole swallowing coins
                $this->drawCircle($pixels, $x(18), $y(10), $r(55), 9);
                $this->drawCircle($pixels, $x(18), $y(10), $r(37), 0);
                for ($i = 0; $i < 5; $i++) {
                    $this->drawCircle($pixels, $x(-68 + $i * 22), $y(-54 + $i * 14), $r(8), 8);
                    $this->drawLine($pixels, $x(-58 + $i * 22), $y(-46 + $i * 14), $x(-28 + $i * 15), $y(-22 + $i * 9), $accent, $r(2));
                }
                break;
            case 23: // broken bonus gift
                $this->drawRect($pixels, $x(-52), $y(-25), $x(52), $y(55), 14);
                $this->drawRect($pixels, $x(-62), $y(-42), $x(62), $y(-20), 8);
                $this->drawRect($pixels, $x(-10), $y(-42), $x(10), $y(55), 8);
                $this->drawCircleOutline($pixels, $x(-20), $y(-52), $r(18), 8, $r(6));
                $this->drawCircleOutline($pixels, $x(20), $y(-52), $r(18), 8, $r(6));
                $this->drawLine($pixels, $x(-8), $y(-20), $x(8), $y(12), 9, $r(5));
                $this->drawLine($pixels, $x(8), $y(12), $x(-6), $y(48), 9, $r(5));
                break;
            case 24: // hourglass
                $this->drawRect($pixels, $x(-48), $y(-60), $x(48), $y(-48), 11);
                $this->drawRect($pixels, $x(-48), $y(48), $x(48), $y(60), 11);
                $this->drawTriangle($pixels, $x(-38), $y(-46), $x(38), $y(-46), $x(0), $y(0), 8);
                $this->drawTriangle($pixels, $x(0), $y(0), $x(-38), $y(46), $x(38), $y(46), 15);
                $this->drawLine($pixels, $x(0), $y(-2), $x(0), $y(18), 8, $r(3));
                break;
            case 25: // news versus casino split
                $this->drawRect($pixels, $x(-66), $y(-54), $x(-5), $y(54), 12);
                for ($row = -36; $row < 40; $row += 15) {
                    $this->drawRect($pixels, $x(-54), $y($row), $x(-16), $y($row + 4), 10);
                }
                $this->drawRect($pixels, $x(5), $y(-54), $x(66), $y(54), 9);
                $this->drawCircle($pixels, $x(36), $y(0), $r(24), 15);
                $this->drawLine($pixels, $x(0), $y(-62), $x(0), $y(62), 8, $r(7));
                break;
            case 26: // target on wallet
                $this->drawRect($pixels, $x(-62), $y(-28), $x(18), $y(42), 7);
                $this->drawFrame($pixels, $x(-62), $y(-28), $x(18), $y(42), 8, $r(4));
                $this->drawCircleOutline($pixels, $x(34), $y(-2), $r(46), 15, $r(6));
                $this->drawCircleOutline($pixels, $x(34), $y(-2), $r(28), 15, $r(5));
                $this->drawCircle($pixels, $x(34), $y(-2), $r(9), 15);
                $this->drawLine($pixels, $x(72), $y(-42), $x(34), $y(-2), 8, $r(5));
                break;
            case 27: // stop hand
                $this->drawCircle($pixels, $x(0), $y(10), $r(42), 14);
                for ($finger = -2; $finger <= 2; $finger++) {
                    $height = 34 + (2 - abs($finger)) * 9;
                    $this->drawRect($pixels, $x($finger * 16 - 6), $y(-20 - $height), $x($finger * 16 + 6), $y(-8), 14);
                }
                $this->drawLine($pixels, $x(-58), $y(-58), $x(58), $y(58), 15, $r(8));
                break;
            case 28: // crossroads
                $this->drawRect($pixels, $x(-8), $y(-62), $x(8), $y(62), 11);
                $this->drawLine($pixels, $x(0), $y(-18), $x(-62), $y(-52), 12, $r(12));
                $this->drawTriangle($pixels, $x(-70), $y(-57), $x(-50), $y(-62), $x(-57), $y(-42), 12);
                $this->drawLine($pixels, $x(0), $y(8), $x(62), $y(42), 15, $r(12));
                $this->drawTriangle($pixels, $x(70), $y(47), $x(50), $y(52), $x(57), $y(32), 15);
                break;
            default: // eye watching casino chip
                $this->drawPolygon($pixels, [[$x(-70), $y(0)], [$x(-35), $y(-35)], [$x(0), $y(-48)], [$x(35), $y(-35)], [$x(70), $y(0)], [$x(35), $y(35)], [$x(0), $y(48)], [$x(-35), $y(35)]], 12);
                $this->drawCircle($pixels, $x(0), $y(0), $r(28), $accent);
                $this->drawCircle($pixels, $x(0), $y(0), $r(13), 9);
                $this->drawCircle($pixels, $x(5), $y(-5), $r(4), 8);
                break;
        }
    }

    private function drawCenteredText(string &$pixels, string $text, int $y, int $scale, int $color): void
    {
        $width = 0;

        foreach (str_split($text) as $character) {
            $width += ($character === ' ' ? 3 : 6) * $scale;
        }

        $this->drawText($pixels, (int) round((self::WIDTH - $width) / 2), $y, $text, $scale, $color);
    }

    private function drawText(string &$pixels, int $x, int $y, string $text, int $scale, int $color): void
    {
        foreach (str_split($text) as $character) {
            if ($character === ' ') {
                $x += 3 * $scale;
                continue;
            }

            $pattern = self::FONT[$character] ?? null;

            if (! $pattern) {
                $x += 6 * $scale;
                continue;
            }

            foreach ($pattern as $row => $line) {
                foreach (str_split($line) as $column => $bit) {
                    if ($bit === '1') {
                        $this->drawRect(
                            $pixels,
                            $x + $column * $scale,
                            $y + $row * $scale,
                            $x + ($column + 1) * $scale - 1,
                            $y + ($row + 1) * $scale - 1,
                            $color,
                        );
                    }
                }
            }

            $x += 6 * $scale;
        }
    }

    private function drawNumber(string &$pixels, int $number, int $digits, int $x, int $y, int $scale, int $color): void
    {
        foreach (str_split(str_pad((string) $number, $digits, '0', STR_PAD_LEFT)) as $digit) {
            $this->drawDigit($pixels, $x, $y, (int) $digit, $color, $scale);
            $x += 4 * $scale;
        }
    }

    private function drawDigit(string &$pixels, int $x, int $y, int $digit, int $color, int $scale): void
    {
        $patterns = [
            ['111', '101', '101', '101', '111'],
            ['010', '110', '010', '010', '111'],
            ['111', '001', '111', '100', '111'],
            ['111', '001', '111', '001', '111'],
            ['101', '101', '111', '001', '001'],
            ['111', '100', '111', '001', '111'],
            ['111', '100', '111', '101', '111'],
            ['111', '001', '010', '010', '010'],
            ['111', '101', '111', '101', '111'],
            ['111', '101', '111', '001', '111'],
        ];

        foreach ($patterns[$digit] as $row => $pattern) {
            foreach (str_split($pattern) as $column => $bit) {
                if ($bit === '1') {
                    $this->drawRect(
                        $pixels,
                        $x + $column * $scale,
                        $y + $row * $scale,
                        $x + ($column + 1) * $scale - 1,
                        $y + ($row + 1) * $scale - 1,
                        $color,
                    );
                }
            }
        }
    }

    private function drawRect(string &$pixels, int $x1, int $y1, int $x2, int $y2, int $color): void
    {
        $x1 = max(0, min(self::WIDTH - 1, $x1));
        $x2 = max(0, min(self::WIDTH - 1, $x2));
        $y1 = max(0, min(self::HEIGHT - 1, $y1));
        $y2 = max(0, min(self::HEIGHT - 1, $y2));
        $byte = chr($color);

        for ($y = min($y1, $y2); $y <= max($y1, $y2); $y++) {
            for ($x = min($x1, $x2); $x <= max($x1, $x2); $x++) {
                $pixels[$y * self::WIDTH + $x] = $byte;
            }
        }
    }

    private function drawFrame(string &$pixels, int $x1, int $y1, int $x2, int $y2, int $color, int $thickness): void
    {
        $this->drawRect($pixels, $x1, $y1, $x2, $y1 + $thickness - 1, $color);
        $this->drawRect($pixels, $x1, $y2 - $thickness + 1, $x2, $y2, $color);
        $this->drawRect($pixels, $x1, $y1, $x1 + $thickness - 1, $y2, $color);
        $this->drawRect($pixels, $x2 - $thickness + 1, $y1, $x2, $y2, $color);
    }

    private function drawLine(string &$pixels, int $x1, int $y1, int $x2, int $y2, int $color, int $thickness = 1): void
    {
        $dx = abs($x2 - $x1);
        $sx = $x1 < $x2 ? 1 : -1;
        $dy = -abs($y2 - $y1);
        $sy = $y1 < $y2 ? 1 : -1;
        $error = $dx + $dy;

        while (true) {
            $radius = max(0, intdiv($thickness, 2));
            $this->drawRect($pixels, $x1 - $radius, $y1 - $radius, $x1 + $radius, $y1 + $radius, $color);

            if ($x1 === $x2 && $y1 === $y2) {
                break;
            }

            $doubleError = 2 * $error;

            if ($doubleError >= $dy) {
                $error += $dy;
                $x1 += $sx;
            }

            if ($doubleError <= $dx) {
                $error += $dx;
                $y1 += $sy;
            }
        }
    }

    private function drawCircle(string &$pixels, int $cx, int $cy, int $radius, int $color): void
    {
        for ($y = -$radius; $y <= $radius; $y++) {
            $span = (int) floor(sqrt(max(0, $radius * $radius - $y * $y)));
            $this->drawRect($pixels, $cx - $span, $cy + $y, $cx + $span, $cy + $y, $color);
        }
    }

    private function drawCircleOutline(string &$pixels, int $cx, int $cy, int $radius, int $color, int $thickness): void
    {
        $this->drawCircle($pixels, $cx, $cy, $radius, $color);
        $this->drawCircle($pixels, $cx, $cy, max(0, $radius - $thickness), 9);
    }

    private function drawTriangle(string &$pixels, int $x1, int $y1, int $x2, int $y2, int $x3, int $y3, int $color): void
    {
        $this->drawPolygon($pixels, [[$x1, $y1], [$x2, $y2], [$x3, $y3]], $color);
    }

    private function drawPolygon(string &$pixels, array $points, int $color): void
    {
        $minY = max(0, min(array_column($points, 1)));
        $maxY = min(self::HEIGHT - 1, max(array_column($points, 1)));
        $count = count($points);

        for ($y = $minY; $y <= $maxY; $y++) {
            $intersections = [];

            for ($i = 0; $i < $count; $i++) {
                [$x1, $y1] = $points[$i];
                [$x2, $y2] = $points[($i + 1) % $count];

                if ($y1 === $y2 || $y < min($y1, $y2) || $y >= max($y1, $y2)) {
                    continue;
                }

                $intersections[] = (int) round($x1 + (($y - $y1) * ($x2 - $x1) / ($y2 - $y1)));
            }

            sort($intersections);

            for ($i = 0; $i + 1 < count($intersections); $i += 2) {
                $this->drawRect($pixels, $intersections[$i], $y, $intersections[$i + 1], $y, $color);
            }
        }
    }

    private function encodePng(string $pixels): string
    {
        $raw = '';

        for ($y = 0; $y < self::HEIGHT; $y++) {
            $raw .= "\x00".substr($pixels, $y * self::WIDTH, self::WIDTH);
        }

        $palette = '';

        foreach (self::PALETTE as $color) {
            $palette .= chr($color[0]).chr($color[1]).chr($color[2]);
        }

        $header = pack('NNC5', self::WIDTH, self::HEIGHT, 8, 3, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', $header)
            .$this->pngChunk('PLTE', $palette)
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
}
