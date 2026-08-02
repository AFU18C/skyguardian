<?php

namespace Tests\Unit;

use App\Services\AntiCasinoTargetRotationService;
use App\Services\DiverseAntiCasinoVisualService;
use Tests\TestCase;

class DiverseAntiCasinoVisualServiceTest extends TestCase
{
    public function test_all_300_designs_are_unique_png_files(): void
    {
        $service = app(DiverseAntiCasinoVisualService::class);
        $hashes = [];

        for ($index = 1; $index <= DiverseAntiCasinoVisualService::DESIGN_COUNT; $index++) {
            $image = $service->generatePng($index);

            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $image);
            $hashes[] = hash('sha256', $image);
        }

        $this->assertCount(DiverseAntiCasinoVisualService::DESIGN_COUNT, array_unique($hashes));
    }

    public function test_all_requested_channels_are_in_rotation(): void
    {
        $service = app(AntiCasinoTargetRotationService::class);
        $urls = [];

        for ($index = 1; $index <= AntiCasinoTargetRotationService::TARGET_COUNT; $index++) {
            $target = $service->targetForIndex($index);
            $urls[] = $target['url'];
        }

        $this->assertSame([
            'https://t.me/kharkivlife',
            'https://t.me/obolonlife',
            'https://t.me/oko_ua',
            'https://t.me/truexanewsua',
            'https://t.me/lachentyt',
            'https://t.me/kievreal1',
            'https://t.me/kyivoperat',
            'https://t.me/+pFvHjtPUufZhMTFi',
            'https://t.me/vanek_nikolaev',
        ], $urls);
    }

    public function test_targeted_caption_stays_within_telegram_limit(): void
    {
        $service = app(AntiCasinoTargetRotationService::class);
        $caption = str_repeat('Довгий текст для перевірки. ', 100);

        for ($index = 1; $index <= 1000; $index++) {
            $result = $service->withTarget($caption, $index);
            $this->assertLessThanOrEqual(1024, mb_strlen($result));
        }
    }
}
