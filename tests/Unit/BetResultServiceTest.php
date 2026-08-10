<?php

namespace Tests\Unit;

use App\Services\BetOddsService;
use App\Services\BetResultService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BetResultServiceTest extends TestCase
{
    private BetResultService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BetResultService(new BetOddsService);
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
}
