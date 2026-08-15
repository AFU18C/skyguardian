<?php

namespace Tests\Unit;

use App\Models\Bet;
use App\Models\BettingSetting;
use App\Services\BetResultService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BetResultServiceTest extends TestCase
{
    private BetResultService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BetResultService;
    }

    #[Test]
    public function draw_is_a_loss_for_match_winner_market(): void
    {
        $this->assertSame('loss', $this->service->settle('П1', 1, 1));
        $this->assertSame('loss', $this->service->settle('П2', 1, 1));
    }

    #[Test]
    public function integer_total_can_be_refunded(): void
    {
        $this->assertSame('refund', $this->service->settle('ТБ 3', 2, 1));
        $this->assertSame('win', $this->service->settle('ТБ 2.5', 2, 1));
    }

    #[Test]
    public function automatic_result_lookup_is_disabled(): void
    {
        $result = $this->service->check(new Bet, new BettingSetting);

        $this->assertSame('pending', $result['result']);
        $this->assertStringContainsString('отключено', $result['result_note']);
    }
}
