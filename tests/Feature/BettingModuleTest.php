<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\BetSearchRun;
use App\Models\BettingSetting;
use App\Models\User;
use App\Services\BetOddsService;
use App\Services\BetSearchService;
use App\Services\WebsiteBetSearchService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BettingModuleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_open_autonomous_betting_section(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.betting.index'))
            ->assertOk()
            ->assertSee('Ставки')
            ->assertSee('Проверить ставки')
            ->assertSee('Сайты-источники')
            ->assertSee('Telegram + сайты');

        $this->assertDatabaseCount('betting_settings', 1);
    }

    #[Test]
    public function admin_can_save_compact_betting_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('admin.betting.settings.update'), [
            'keywords_text' => "ставка дня\nтотал больше",
            'telegram_channels_text' => "https://t.me/Sports_Channel/123\n@sports_channel\nhttps://t.me/+PrivateInvite123\nhttps://t.me/joinchat/LegacyInvite456\n-1001234567890",
            'website_sources_text' => "Sports Tips | https://tips.example/predictions\n# Reserve Tips | https://reserve.example/bets",
            'freshness_hours' => 12,
            'minimum_ai_score' => 85,
            'maximum_results' => 15,
            'primary_source_name' => 'BETON',
            'primary_source_url' => 'https://beton.ua/sportsbook',
            'found_retention_days' => 5,
            'rejected_retention_days' => 9,
        ])->assertSessionHasNoErrors();

        $settings = BettingSetting::current();
        $this->assertSame(12, $settings->freshness_hours);
        $this->assertSame(85, $settings->minimum_ai_score);
        $this->assertSame(['ставка дня', 'тотал больше'], $settings->keywords);
        $this->assertSame([
            '@sports_channel',
            'https://t.me/+PrivateInvite123',
            'https://t.me/+LegacyInvite456',
            '-1001234567890',
        ], $settings->telegram_channels);
        $this->assertSame([
            ['name' => 'Sports Tips', 'url' => 'https://tips.example/predictions', 'enabled' => true],
            ['name' => 'Reserve Tips', 'url' => 'https://reserve.example/bets', 'enabled' => false],
        ], $settings->website_sources);
    }

    #[Test]
    public function manual_website_search_is_queued_instead_of_running_inside_the_web_request(): void
    {
        $user = User::factory()->create();
        BettingSetting::current()->update([
            'website_sources' => [[
                'name' => 'Sports Tips',
                'url' => 'https://93.184.216.34/predictions',
                'enabled' => true,
            ]],
        ]);

        $this->actingAs($user)->post(route('admin.betting.search'), [
            'search_mode' => 'websites',
        ])->assertRedirect(route('admin.betting.index', ['tab' => 'search']));

        $run = BetSearchRun::query()->sole();
        $this->assertSame(BetSearchRun::STATUS_PENDING, $run->status);
        $this->assertSame('websites', $run->search_mode);
        $this->assertSame($user->id, $run->requested_by_user_id);
        $this->assertNull($run->started_at);
    }

    #[Test]
    public function duplicate_published_bet_is_protected_by_fingerprint(): void
    {
        $fingerprint = hash('sha256', 'real-barca-tb25');
        Bet::query()->create([
            'fingerprint' => $fingerprint,
            'status' => Bet::STATUS_PUBLISHED,
            'event_name' => 'Реал — Барселона',
            'market' => 'ТБ 2.5',
            'ai_score' => 88,
        ]);

        $this->expectException(QueryException::class);
        Bet::query()->create([
            'fingerprint' => $fingerprint,
            'status' => Bet::STATUS_PUBLISHED,
            'event_name' => 'Реал — Барселона',
            'market' => 'ТБ 2.5',
            'ai_score' => 88,
        ]);
    }

    #[Test]
    public function manual_archive_cleanup_does_not_remove_pending_publications(): void
    {
        $user = User::factory()->create();
        Bet::query()->create([
            'fingerprint' => hash('sha256', 'pending'),
            'status' => Bet::STATUS_PUBLISHED,
            'event_name' => 'Матч 1',
            'market' => 'П1',
            'ai_score' => 80,
            'result' => 'pending',
        ]);
        Bet::query()->create([
            'fingerprint' => hash('sha256', 'completed'),
            'status' => Bet::STATUS_PUBLISHED,
            'event_name' => 'Матч 2',
            'market' => 'П2',
            'ai_score' => 80,
            'result' => 'win',
        ]);

        $this->actingAs($user)->delete(route('admin.betting.archive.clear'), [
            'scope' => 'completed',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bets', ['event_name' => 'Матч 1', 'result' => 'pending']);
        $this->assertDatabaseMissing('bets', ['event_name' => 'Матч 2']);
    }

    #[Test]
    public function search_stores_only_a_bet_verified_as_available_on_beton(): void
    {
        $settings = $this->websiteBettingSettings();
        $this->mockWebsitePrediction();
        $this->mock(BetOddsService::class, function ($mock): void {
            $mock->shouldReceive('lookup')->once()->andReturn($this->oddsResult(eventFound: true));
        });

        $run = app(BetSearchService::class)->run($settings, 'websites');

        $this->assertSame(1, (int) $run->bets_found);
        $this->assertDatabaseHas('bets', [
            'event_name' => 'Сан-Бернарду — Ботафого',
            'status' => Bet::STATUS_FOUND,
            'primary_odds' => 1.84,
        ]);
    }

    #[Test]
    public function search_rejects_an_event_absent_from_beton(): void
    {
        $settings = $this->websiteBettingSettings();
        $this->mockWebsitePrediction();
        $this->mock(BetOddsService::class, function ($mock): void {
            $mock->shouldReceive('lookup')->once()->andReturn($this->oddsResult(eventFound: false, odds: null));
        });

        $run = app(BetSearchService::class)->run($settings, 'websites');

        $this->assertSame(0, (int) $run->bets_found);
        $this->assertDatabaseCount('bets', 0);
    }

    #[Test]
    public function search_rejects_a_finished_beton_event(): void
    {
        $settings = $this->websiteBettingSettings();
        $this->mockWebsitePrediction();
        $this->mock(BetOddsService::class, function ($mock): void {
            $mock->shouldReceive('lookup')->once()->andReturn($this->oddsResult(eventFound: true, finished: true));
        });

        $run = app(BetSearchService::class)->run($settings, 'websites');

        $this->assertSame(0, (int) $run->bets_found);
        $this->assertDatabaseCount('bets', 0);
    }

    private function websiteBettingSettings(): BettingSetting
    {
        $settings = BettingSetting::current();
        $settings->update([
            'website_sources' => [[
                'name' => 'Sports Tips',
                'url' => 'https://93.184.216.34/predictions',
                'enabled' => true,
            ]],
            'primary_source_name' => 'BETON',
            'primary_source_url' => 'https://beton.ua/sportsbook',
            'minimum_ai_score' => 80,
        ]);

        return $settings->fresh();
    }

    private function mockWebsitePrediction(): void
    {
        $this->mock(WebsiteBetSearchService::class, function ($mock): void {
            $mock->shouldReceive('search')->once()->andReturn([
                'messages' => [[
                    'id' => 'prediction-1',
                    'date' => now()->toIso8601String(),
                    'text' => "Сан-Бернарду — Ботафого\nТМ 3.5",
                    'source_type' => 'website',
                    'source_name' => 'Sports Tips',
                    'url' => 'https://93.184.216.34/predictions',
                ]],
                'source_errors' => [],
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function oddsResult(bool $eventFound, bool $finished = false, ?float $odds = 1.84): array
    {
        return [
            'primary_odds' => $odds,
            'reserve_odds' => null,
            'selected_odds' => $odds,
            'selected_odds_source' => $odds ? 'BETON' : null,
            'odds_snapshot' => [
                'primary' => [
                    'name' => 'BETON',
                    'url' => 'https://beton.ua/sportsbook',
                    'event_found' => $eventFound,
                    'finished' => $finished,
                    'odds' => $odds,
                ],
                'reserve' => [],
            ],
            'odds_checked_at' => now(),
        ];
    }
}
