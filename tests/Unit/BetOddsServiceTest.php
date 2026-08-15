<?php

namespace Tests\Unit;

use App\Services\BetOddsService;
use App\Services\PublicUrlGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BetOddsServiceTest extends TestCase
{
    #[Test]
    public function it_extracts_only_exact_structured_event_odds_and_finished_score(): void
    {
        $payload = <<<'HTML'
        <script type="application/json">
        {
          "eventId":"match-77",
          "leagueName":"La Liga",
          "startsAt":"2026-08-12T19:00:00+03:00",
          "status":"Finished",
          "homeTeam":{"name":"Реал Мадрид"},
          "awayTeam":{"name":"Барселона"},
          "markets":[{"name":"Тотал больше 2.5","price":1.72}],
          "score":{"home":3,"away":1}
        }
        </script>
        HTML;

        $result = (new BetOddsService($this->publicGuard()))->extract($payload, [
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
    public function it_does_not_guess_results_or_odds_from_unstructured_page_text(): void
    {
        $result = (new BetOddsService($this->publicGuard()))->extract(
            '<html><body>Реал Мадрид — Барселона 3:1, ТБ 2.5 — 1.72</body></html>',
            ['home_team' => 'Реал Мадрид', 'away_team' => 'Барселона', 'market' => 'ТБ 2.5'],
        );

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_rejects_ambiguous_structured_events_with_the_same_teams(): void
    {
        $payload = json_encode(['events' => [
            [
                'eventId' => 'first',
                'homeTeam' => ['name' => 'Реал Мадрид'],
                'awayTeam' => ['name' => 'Барселона'],
                'startsAt' => '2026-08-12T19:00:00+03:00',
                'markets' => [['name' => 'Победа 1', 'price' => 1.8]],
            ],
            [
                'eventId' => 'second',
                'homeTeam' => ['name' => 'Реал Мадрид'],
                'awayTeam' => ['name' => 'Барселона'],
                'startsAt' => '2026-09-12T19:00:00+03:00',
                'markets' => [['name' => 'Победа 1', 'price' => 1.9]],
            ],
        ]], JSON_THROW_ON_ERROR);

        $service = new BetOddsService($this->publicGuard());
        $ambiguous = $service->extract($payload, [
            'home_team' => 'Реал Мадрид',
            'away_team' => 'Барселона',
            'market' => 'П1',
        ]);
        $exact = $service->extract($payload, [
            'home_team' => 'Реал Мадрид',
            'away_team' => 'Барселона',
            'market' => 'П1',
            'starts_at' => '2026-09-12T16:00:00Z',
        ]);

        $this->assertSame([], $ambiguous);
        $this->assertSame('second', $exact['event_id']);
        $this->assertSame(1.9, $exact['odds']);
    }

    #[Test]
    public function it_downloads_each_configured_source_only_once_per_search(): void
    {
        Http::fake(['https://source.test/*' => Http::response('{"events":[]}', 200)]);
        $service = new BetOddsService($this->publicGuard());
        $bet = ['home_team' => 'Реал', 'away_team' => 'Барселона', 'market' => 'П1'];

        $service->inspect('https://source.test/sports', $bet);
        $service->inspect('https://source.test/sports', $bet);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://source.test/sports');
    }

    #[Test]
    public function it_rejects_a_redirect_to_a_private_network(): void
    {
        Http::fake([
            'https://source.test/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private']),
        ]);

        $result = (new BetOddsService($this->publicGuard()))->inspect('https://source.test/sports', [
            'home_team' => 'Реал',
            'away_team' => 'Барселона',
            'market' => 'П1',
        ]);

        $this->assertFalse($result['event_found']);
        $this->assertStringContainsString('внутреннюю', $result['error']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_reports_when_the_source_blocks_the_server_location(): void
    {
        Http::fake(['https://source.test/*' => Http::response('<title>Beton Error</title><h1>Немає доступу?</h1>', 200)]);

        $result = (new BetOddsService($this->publicGuard()))->inspect('https://source.test/sports', [
            'home_team' => 'Реал',
            'away_team' => 'Барселона',
            'market' => 'П1',
        ]);

        $this->assertFalse($result['event_found']);
        $this->assertStringContainsString('ограничил доступ', $result['error']);
    }

    private function publicGuard(): PublicUrlGuard
    {
        return new PublicUrlGuard(fn (string $host): array => ['93.184.216.34']);
    }
}
