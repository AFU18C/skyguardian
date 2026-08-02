<?php

namespace Tests\Unit;

use App\Services\SkyGuardianPromoCampaignService;
use ReflectionMethod;
use Tests\TestCase;

class SkyGuardianPromoCampaignServiceTest extends TestCase
{
    public function test_public_status_redacts_telegram_bot_tokens(): void
    {
        $service = app(SkyGuardianPromoCampaignService::class);
        $method = new ReflectionMethod($service, 'redactSensitiveError');
        $fakeToken = '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghi';
        $error = 'Timeout for https://api.telegram.org/bot'.$fakeToken.'/sendPhoto';

        $redacted = $method->invoke($service, $error);

        $this->assertStringNotContainsString($fakeToken, $redacted);
        $this->assertStringContainsString('bot[REDACTED]', $redacted);
    }
}
