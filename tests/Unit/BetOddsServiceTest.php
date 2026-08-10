<?php

namespace Tests\Unit;

use App\Services\BetOddsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
}
