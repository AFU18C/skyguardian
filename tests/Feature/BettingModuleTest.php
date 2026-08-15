<?php

namespace Tests\Feature;

use App\Jobs\RunBetSearch;
use App\Models\Bet;
use App\Models\BetSearchRun;
use App\Models\BettingSetting;
use App\Models\User;
use App\Services\PublicUrlGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
            ->assertSee('Telegram + сайты')
            ->assertSee('оценка качества')
            ->assertDontSee('ИИ-оценка');

        $this->assertDatabaseCount('betting_settings', 1);
    }

    #[Test]
    public function admin_can_save_compact_betting_settings(): void
    {
        $user = User::factory()->create();
        $this->app->instance(PublicUrlGuard::class, $this->publicGuard());

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
    public function website_search_is_dispatched_to_the_dedicated_queue_and_returns_immediately(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        BettingSetting::current()->update([
            'website_sources' => [[
                'name' => 'Sports Tips',
                'url' => 'https://tips.example/predictions',
                'enabled' => true,
            ]],
        ]);

        $this->actingAs($user)->post(route('admin.betting.search'), [
            'search_mode' => 'websites',
        ])->assertRedirect(route('admin.betting.index', ['tab' => 'search']));

        $run = BetSearchRun::query()->firstOrFail();
        $this->assertSame('queued', $run->status);
        $this->assertSame('websites', $run->search_mode);
        Queue::assertPushedOn('betting', RunBetSearch::class, fn (RunBetSearch $job): bool => $job->runId === $run->id);
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

    private function publicGuard(): PublicUrlGuard
    {
        return new PublicUrlGuard(fn (string $host): array => ['93.184.216.34']);
    }
}
