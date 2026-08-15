<?php

namespace Tests\Unit;

use App\Services\BetOddsService;
use App\Services\BettingBrowserClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BetOddsServiceTest extends TestCase
{
    #[Test]
    public function it_extracts_event_metadata_odds_and_finished_score_from_source_payload(): void
    {
        $payload = <<<'HTML'
        <script type="application/json">
        {"eventId":"match-77","leagueName":"La Liga","startsAt":"2026-08-12T19:00:00+03:00","status":"Finished","event":"Реал Мадрид Тотал больше 2.5 1.72 3:1 Барселона"}
        </script>
        HTML;

        $result = (new BetOddsService)->extract($payload, [
            'home_team' => 'Реал Мадрид',
            'away_team' => 'Барселона',
            'market' => 'ТБ 2.5',
        ]);

        $this->assertTrue($result['event_found']);
        $this->assertSame(1.72, $result['odds']);
        $this->assertSame('match-77', $result['event_id']);
        $this->assertSame('La Liga', $result['tournament']);
        $this->assertSame('2026-08-12T19:00:00+03:00', $result['starts_at']);
        $this->assertSame([3, 1], $result['score']);
        $this->assertTrue($result['finished']);
    }

    #[Test]
    public function it_downloads_each_configured_source_only_once_per_search(): void
    {
        Http::fake(['https://source.test/*' => Http::response('Реал Барселона П1 1.80', 200)]);
        $service = new BetOddsService;
        $bet = ['home_team' => 'Реал', 'away_team' => 'Барселона', 'market' => 'П1'];

        $service->inspect('https://source.test/sports', $bet);
        $service->inspect('https://source.test/sports', $bet);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://source.test/sports');
    }

    #[Test]
    public function it_reports_when_the_source_blocks_the_server_location(): void
    {
        Http::fake(['https://source.test/*' => Http::response('<title>Beton Error</title><h1>Немає доступу?</h1>', 200)]);

        $result = (new BetOddsService)->inspect('https://source.test/sports', [
            'home_team' => 'Реал',
            'away_team' => 'Барселона',
            'market' => 'П1',
        ]);

        $this->assertFalse($result['event_found']);
        $this->assertStringContainsString('ограничил доступ', $result['error']);
    }

    #[Test]
    public function it_finds_a_live_event_market_and_odds_in_the_rendered_beton_line(): void
    {
        Http::fake(['https://beton.ua/*' => Http::response('<html>sportsbook</html>', 200)]);
        $browser = Mockery::mock(BettingBrowserClient::class);
        $browser->shouldReceive('inspect')->once()->andReturn([
            'body' => 'LIVE Sao Bernardo Botafogo Тотал менше 3.5 1.84',
            'http_status' => 200,
        ]);

        $result = (new BetOddsService($browser))->inspect('https://beton.ua/sportsbook', [
            'home_team' => 'Сан-Бернарду',
            'away_team' => 'Ботафого',
            'market' => 'ТМ 3.5',
        ]);

        $this->assertTrue($result['event_found']);
        $this->assertFalse($result['finished']);
        $this->assertSame(1.84, $result['odds']);
    }

    #[Test]
    public function it_marks_a_completed_beton_event_as_finished(): void
    {
        Http::fake(['https://beton.ua/*' => Http::response('<html>sportsbook</html>', 200)]);
        $browser = Mockery::mock(BettingBrowserClient::class);
        $browser->shouldReceive('inspect')->once()->andReturn([
            'body' => 'Sao Bernardo Botafogo Finished Тотал менше 3.5 1.84',
            'http_status' => 200,
        ]);

        $result = (new BetOddsService($browser))->inspect('https://beton.ua/sportsbook', [
            'home_team' => 'Сан-Бернарду',
            'away_team' => 'Ботафого',
            'market' => 'ТМ 3.5',
        ]);

        $this->assertTrue($result['event_found']);
        $this->assertTrue($result['finished']);
    }

    #[Test]
    public function it_rejects_a_stale_dated_event_even_without_a_finished_label(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16 00:20:00', 'Europe/Kyiv'));

        $result = (new BetOddsService)->extract(
            'Бразильська Серія В 14/08 • 15:30 Сан-Бернарду Ботафого Тотал менше 3.5 1.84',
            [
                'home_team' => 'Сан-Бернарду',
                'away_team' => 'Ботафого',
                'market' => 'ТМ 3.5',
            ],
        );
        CarbonImmutable::setTestNow();

        $this->assertTrue($result['event_found']);
        $this->assertTrue($result['finished']);
        $this->assertSame('2026-08-14T15:30:00+03:00', $result['starts_at']);
    }
}
