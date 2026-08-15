<?php

namespace Tests\Unit;

use App\Services\PublicUrlGuard;
use App\Services\WebsiteBetSearchService;
use App\Services\WebsiteBrowserClient;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebsiteBetSearchServiceTest extends TestCase
{
    #[Test]
    public function it_collects_browser_rendered_keyword_context_from_enabled_websites_only(): void
    {
        $browser = Mockery::mock(WebsiteBrowserClient::class);
        $browser->shouldReceive('fetch')
            ->once()
            ->with(Mockery::on(fn (array $sources): bool => count($sources) === 1
                && $sources[0]['url'] === 'https://tips.example/predictions'), ['ставка дня'], 24)
            ->andReturn([
                'documents' => [[
                    'name' => 'Sports Tips',
                    'requested_url' => 'https://tips.example/predictions',
                    'final_url' => 'https://tips.example/rendered',
                    'canonical_url' => 'https://tips.example/predictions/real-barcelona',
                    'title' => 'Реал — Барселона',
                    'text' => "Ставка дня\nП1, коэффициент 1.85",
                    'published_at' => now()->subHour()->toIso8601String(),
                    'fetched_at' => now()->toIso8601String(),
                ]],
                'errors' => [],
            ]);

        $service = new WebsiteBetSearchService($browser, $this->publicGuard());
        $result = $service->search([
            ['name' => 'Sports Tips', 'url' => 'https://tips.example/predictions', 'enabled' => true],
            ['name' => 'Disabled', 'url' => 'https://disabled.example/bets', 'enabled' => false],
        ], ['ставка дня'], 20);

        $this->assertCount(1, $result['messages']);
        $this->assertSame([], $result['source_errors']);
        $this->assertSame('website', $result['messages'][0]['source_type']);
        $this->assertSame('Sports Tips', $result['messages'][0]['source_name']);
        $this->assertSame('https://tips.example/predictions/real-barcelona', $result['messages'][0]['url']);
        $this->assertStringContainsString('Реал — Барселона', $result['messages'][0]['text']);
    }

    #[Test]
    public function it_keeps_unknown_publication_dates_unknown_instead_of_using_fetch_time(): void
    {
        $browser = Mockery::mock(WebsiteBrowserClient::class);
        $browser->shouldReceive('fetch')->once()->andReturn([
            'documents' => [[
                'name' => 'Tips',
                'requested_url' => 'https://tips.example/predictions',
                'final_url' => 'https://tips.example/predictions',
                'title' => 'Реал — Барселона',
                'text' => 'Ставка дня П1',
                'published_at' => null,
                'fetched_at' => now()->toIso8601String(),
            ]],
            'errors' => [],
        ]);

        $result = (new WebsiteBetSearchService($browser, $this->publicGuard()))->search([
            ['name' => 'Tips', 'url' => 'https://tips.example/predictions', 'enabled' => true],
        ], ['ставка дня'], 20);

        $this->assertNull($result['messages'][0]['date']);
        $this->assertNotNull($result['messages'][0]['fetched_at']);
    }

    #[Test]
    public function it_rejects_direct_private_network_addresses_before_opening_a_browser(): void
    {
        $browser = Mockery::mock(WebsiteBrowserClient::class);
        $browser->shouldNotReceive('fetch');

        $result = (new WebsiteBetSearchService($browser, $this->publicGuard()))->search([
            ['name' => 'Internal', 'url' => 'http://127.0.0.1/private', 'enabled' => true],
        ], ['ставка'], 20);

        $this->assertSame([], $result['messages']);
        $this->assertCount(1, $result['source_errors']);
    }

    private function publicGuard(): PublicUrlGuard
    {
        return new PublicUrlGuard(fn (string $host): array => ['93.184.216.34']);
    }
}
