<?php

namespace Tests\Unit;

use App\Services\WebsiteBetSearchService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebsiteBetSearchServiceTest extends TestCase
{
    #[Test]
    public function it_collects_keyword_context_from_enabled_websites_only(): void
    {
        Http::fake([
            'https://tips.example/*' => Http::response('<article><h2>Реал — Барселона</h2><p>Ставка дня</p><p>П1, коэффициент 1.85</p></article>'),
        ]);

        $result = (new WebsiteBetSearchService)->search([
            ['name' => 'Sports Tips', 'url' => 'https://tips.example/predictions', 'enabled' => true],
            ['name' => 'Disabled', 'url' => 'https://disabled.example/bets', 'enabled' => false],
        ], ['ставка дня'], 20);

        $this->assertCount(1, $result['messages']);
        $this->assertSame([], $result['source_errors']);
        $this->assertSame('website', $result['messages'][0]['source_type']);
        $this->assertSame('Sports Tips', $result['messages'][0]['source_name']);
        $this->assertStringContainsString('Реал — Барселона', $result['messages'][0]['text']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tips.example/predictions');
    }

    #[Test]
    public function it_rejects_direct_private_network_addresses(): void
    {
        Http::fake();

        $result = (new WebsiteBetSearchService)->search([
            ['name' => 'Internal', 'url' => 'http://127.0.0.1/private', 'enabled' => true],
        ], ['ставка'], 20);

        $this->assertSame([], $result['messages']);
        $this->assertCount(1, $result['source_errors']);
        Http::assertNothingSent();
    }
}
