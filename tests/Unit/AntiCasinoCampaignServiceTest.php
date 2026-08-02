<?php

namespace Tests\Unit;

use App\Services\AntiCasinoCampaignService;
use ReflectionMethod;
use Tests\TestCase;

class AntiCasinoCampaignServiceTest extends TestCase
{
    public function test_all_captions_are_unique_and_fit_telegram_photo_limit(): void
    {
        $service = app(AntiCasinoCampaignService::class);
        $method = new ReflectionMethod($service, 'caption');
        $captions = [];

        for ($index = 1; $index <= AntiCasinoCampaignService::TOTAL_POSTS; $index++) {
            $caption = $method->invoke($service, $index);

            $this->assertLessThanOrEqual(1024, mb_strlen($caption));
            $captions[] = $caption;
        }

        $this->assertCount(AntiCasinoCampaignService::TOTAL_POSTS, array_unique($captions));
    }

    public function test_generated_campaign_images_are_valid_and_unique_png_files(): void
    {
        $service = app(AntiCasinoCampaignService::class);
        $method = new ReflectionMethod($service, 'generatePng');
        $hashes = [];

        foreach ([1, 2, 500, 1000] as $index) {
            $image = $method->invoke($service, $index);

            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $image);
            $hashes[] = hash('sha256', $image);
        }

        $this->assertCount(4, array_unique($hashes));
    }
}
