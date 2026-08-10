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
            'https://93.184.216.34/*' => Http::response(
                '<article><h2>Реал — Барселона</h2><p>Ставка дня</p><p>П1, коэффициент 1.85</p></article>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $result = (new WebsiteBetSearchService)->search([
            ['name' => 'Sports Tips', 'url' => 'https://93.184.216.34/predictions', 'enabled' => true],
            ['name' => 'Disabled', 'url' => 'https://93.184.216.34/disabled', 'enabled' => false],
        ], ['ставка дня'], 20);

        $this->assertCount(1, $result['messages']);
        $this->assertSame([], $result['source_errors']);
        $this->assertSame('website', $result['messages'][0]['source_type']);
        $this->assertSame('Sports Tips', $result['messages'][0]['source_name']);
        $this->assertStringContainsString('Реал — Барселона', $result['messages'][0]['text']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.34/predictions');
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

    #[Test]
    public function it_revalidates_redirect_targets_and_blocks_private_redirects(): void
    {
        Http::fake([
            'https://93.184.216.34/start' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        $result = (new WebsiteBetSearchService)->search([
            ['name' => 'Redirector', 'url' => 'https://93.184.216.34/start', 'enabled' => true],
        ], ['ставка'], 20);

        $this->assertSame([], $result['messages']);
        $this->assertCount(1, $result['source_errors']);
        $this->assertStringContainsString('локальный', mb_strtolower($result['source_errors'][0]['error']));
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_rejects_urls_with_credentials_or_nonstandard_ports(): void
    {
        Http::fake();

        $result = (new WebsiteBetSearchService)->search([
            ['name' => 'Credentials', 'url' => 'https://user:pass@93.184.216.34/private', 'enabled' => true],
            ['name' => 'Port', 'url' => 'https://93.184.216.34:8443/private', 'enabled' => true],
        ], ['ставка'], 20);

        $this->assertSame([], $result['messages']);
        $this->assertCount(2, $result['source_errors']);
        Http::assertNothingSent();
    }
}
