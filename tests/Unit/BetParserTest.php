<?php

namespace Tests\Unit;

use App\Services\BetParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BetParserTest extends TestCase
{
    #[Test]
    public function it_extracts_event_market_and_odds(): void
    {
        $bet = (new BetParser)->parse([
            'id' => 15,
            'date' => '2026-08-10T08:00:00+00:00',
            'text' => "⚽ Реал Мадрид — Барселона\nСтавка: ТБ 2.5\nКоэффициент: 1.72",
            'chat_title' => 'Прогнозы',
        ]);

        $this->assertNotNull($bet);
        $this->assertSame('Реал Мадрид — Барселона', $bet['event_name']);
        $this->assertSame('ТБ 2.5', $bet['market']);
        $this->assertSame(1.72, $bet['telegram_odds']);
        $this->assertGreaterThanOrEqual(80, $bet['ai_score']);
    }

    #[Test]
    public function it_rejects_incomplete_messages(): void
    {
        $this->assertNull((new BetParser)->parse(['text' => 'Уверенная ставка дня, коэффициент 1.90']));
    }

    #[Test]
    public function it_extracts_sport_tournament_start_time_and_handicap(): void
    {
        $bet = (new BetParser)->parse([
            'date' => '2026-08-10T08:00:00+00:00',
            'text' => "🏀 Лейкерс — Бостон\nЛига: NBA\n12.08.2026 20:30\nФора 1 (-3.5), коф 1.91",
        ]);

        $this->assertNotNull($bet);
        $this->assertSame('Баскетбол', $bet['sport']);
        $this->assertSame('NBA', $bet['tournament']);
        $this->assertSame('Ф1 -3.5', $bet['market']);
        $this->assertStringStartsWith('2026-08-12T20:30:00', $bet['starts_at']);
    }

    #[Test]
    public function rematches_on_different_days_have_different_fingerprints(): void
    {
        $parser = new BetParser;
        $first = $parser->parse([
            'date' => '2026-08-10T08:00:00+00:00',
            'text' => "Реал — Барселона\n10.08.2026 20:00\nП1",
        ]);
        $second = $parser->parse([
            'date' => '2026-08-11T08:00:00+00:00',
            'text' => "Реал — Барселона\n11.08.2026 20:00\nП1",
        ]);

        $this->assertNotSame($first['fingerprint'], $second['fingerprint']);
    }
}
