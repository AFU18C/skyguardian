<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Bet;
use App\Models\GroupChannelAlertCard;
use App\Models\GroupChannelAlertEvent;
use App\Models\GroupChannelBot;
use App\Models\GroupChannelPublication;
use App\Models\GroupChannelWebhookUpdate;
use App\Models\User;
use App\Services\GroupChannelPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OperationalSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_timeout_state_requires_manual_reconciliation_and_is_not_retried(): void
    {
        $user = User::factory()->create();
        $bot = $this->bot(['publications']);
        Http::fake(['*' => Http::response([], 500)]);

        $this->actingAs($user)->post(route('admin.group-channel.publications.store', $bot), [
            'type' => GroupChannelPublication::TYPE_TEXT,
            'text' => 'Однократная публикация',
            'action' => 'send',
        ])->assertRedirect();

        $publication = GroupChannelPublication::query()->firstOrFail();
        $this->assertSame(GroupChannelPublication::STATUS_UNCERTAIN, $publication->status);
        Http::assertSentCount(1);

        $this->actingAs($user)
            ->delete(route('admin.group-channel.publications.destroy', [$bot, $publication]))
            ->assertStatus(409);
        $this->assertDatabaseHas('group_channel_publications', ['id' => $publication->id]);

        $this->actingAs($user)->post(route('admin.group-channel.publications.send', [$bot, $publication]))
            ->assertRedirect();
        Http::assertSentCount(1);

        $this->actingAs($user)->post(route('admin.group-channel.publications.resolve', [$bot, $publication]), [
            'resolution' => 'retry',
        ])->assertRedirect();
        $this->assertSame(GroupChannelPublication::STATUS_DRAFT, $publication->fresh()->status);
    }

    public function test_stale_sending_states_are_quarantined_instead_of_retried(): void
    {
        $bot = $this->bot(['publications']);
        $publication = $bot->publications()->create([
            'type' => GroupChannelPublication::TYPE_TEXT,
            'text' => 'Публикация',
            'status' => GroupChannelPublication::STATUS_SENDING,
            'sending_started_at' => now()->subMinutes(11),
        ]);
        $bet = Bet::query()->create([
            'fingerprint' => hash('sha256', 'stale-bet'),
            'status' => Bet::STATUS_PUBLISHING,
            'event_name' => 'Матч',
            'market' => 'П1',
            'ai_score' => 80,
        ]);
        $bet->forceFill(['updated_at' => now()->subMinutes(11)])->saveQuietly();
        $alertEvent = $bot->alertEvents()->create([
            'event_key' => hash('sha256', 'stale-alert-event'),
            'kind' => GroupChannelAlertEvent::KIND_END,
            'region_uid' => '14',
            'scope_region_uid' => '14',
            'region_name' => 'Київська область',
            'alert_type' => 'air_raid',
            'event_at' => now(),
            'status' => GroupChannelAlertEvent::STATUS_SENDING,
            'sending_started_at' => now()->subMinutes(11),
        ]);
        $alertCard = $bot->alertCards()->create([
            'scope_region_uid' => '14',
            'alert_type' => 'air_raid',
            'snapshot_hash' => hash('sha256', 'stale-alert-card'),
            'pending_snapshot_hash' => hash('sha256', 'pending-alert-card'),
            'delivery_status' => GroupChannelAlertCard::STATUS_SENDING,
            'sending_started_at' => now()->subMinutes(11),
        ]);

        $this->artisan('skyguardian:deliveries:mark-uncertain')->assertSuccessful();

        $this->assertSame(GroupChannelPublication::STATUS_UNCERTAIN, $publication->fresh()->status);
        $this->assertSame(Bet::STATUS_PUBLICATION_UNCERTAIN, $bet->fresh()->status);
        $this->assertSame(GroupChannelAlertEvent::STATUS_UNCERTAIN, $alertEvent->fresh()->status);
        $this->assertSame(GroupChannelAlertCard::STATUS_UNCERTAIN, $alertCard->fresh()->delivery_status);
    }

    public function test_result_delivery_ambiguity_is_blocked_until_an_administrator_resolves_it(): void
    {
        $user = User::factory()->create();
        $bot = $this->bot([]);
        $bet = Bet::query()->create([
            'fingerprint' => hash('sha256', 'result-bet'),
            'status' => Bet::STATUS_PUBLISHED,
            'event_name' => 'Матч',
            'market' => 'П1',
            'selected_odds' => 1.8,
            'ai_score' => 80,
            'result' => 'win',
            'publication_bot_id' => $bot->id,
            'published_at' => now(),
        ]);
        Http::fake(['*' => Http::response([], 500)]);

        $this->actingAs($user)->post(route('admin.betting.send-result', $bet))->assertRedirect();

        $this->assertSame(Bet::RESULT_PUBLICATION_UNCERTAIN, $bet->fresh()->result_publication_status);
        Http::assertSentCount(1);

        $this->actingAs($user)->post(route('admin.betting.send-result', $bet))->assertRedirect();
        Http::assertSentCount(1);
    }

    public function test_failed_deletions_stop_after_the_tenth_attempt(): void
    {
        $bot = $this->bot(['publications', 'auto_delete_publications']);
        $publication = $bot->publications()->create([
            'type' => GroupChannelPublication::TYPE_TEXT,
            'text' => 'Удалить',
            'status' => GroupChannelPublication::STATUS_SENT,
            'sent_at' => now(),
            'telegram_message_id' => '900',
            'telegram_message_ids' => ['900'],
            'deletion_attempts' => 9,
        ]);
        Http::fake(['*' => Http::response([], 500)]);

        try {
            app(GroupChannelPublicationService::class)->delete($publication);
            $this->fail('Deletion failure was expected.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $publication->refresh();
        $this->assertSame(10, $publication->deletion_attempts);
        $this->assertNotNull($publication->delete_failed_at);
        $this->assertNull($publication->next_delete_attempt_at);

        $bot->update(['is_active' => false]);
        $unavailablePublication = $bot->publications()->create([
            'type' => GroupChannelPublication::TYPE_TEXT,
            'text' => 'Недоступный бот',
            'status' => GroupChannelPublication::STATUS_SENT,
            'sent_at' => now(),
            'telegram_message_id' => '901',
            'telegram_message_ids' => ['901'],
            'deletion_attempts' => 9,
        ]);
        try {
            app(GroupChannelPublicationService::class)->delete($unavailablePublication);
            $this->fail('Deletion preflight failure was expected.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
        $this->assertSame(10, $unavailablePublication->fresh()->deletion_attempts);
        $this->assertNotNull($unavailablePublication->fresh()->delete_failed_at);

        $message = $bot->messages()->create([
            'telegram_message_id' => '902',
            'text' => 'Недоступный бот',
            'delete_at' => now()->subMinute(),
            'deletion_attempts' => 9,
        ]);
        $this->artisan('skyguardian:group-channel-publications:process')->assertSuccessful();
        $this->assertSame(10, $message->fresh()->deletion_attempts);
        $this->assertNotNull($message->fresh()->delete_failed_at);
    }

    public function test_interrupted_tenth_webhook_attempt_is_dead_lettered(): void
    {
        $bot = $this->bot([]);
        $update = GroupChannelWebhookUpdate::query()->create([
            'group_channel_bot_id' => $bot->id,
            'telegram_update_id' => 990,
            'payload' => ['update_id' => 990],
            'status' => GroupChannelWebhookUpdate::STATUS_PROCESSING,
            'attempts' => 10,
        ]);
        $update->forceFill(['updated_at' => now()->subMinutes(3)])->saveQuietly();

        $this->artisan('skyguardian:group-channel-webhook-updates:process')->assertSuccessful();

        $update->refresh();
        $this->assertSame(GroupChannelWebhookUpdate::STATUS_DEAD, $update->status);
        $this->assertNotNull($update->dead_lettered_at);
    }

    public function test_retention_prunes_expired_records_but_keeps_pending_deletions(): void
    {
        config()->set('skyguardian.retention.group_channel_messages_days', 30);
        config()->set('skyguardian.retention.failed_webhook_updates_days', 30);
        config()->set('skyguardian.retention.audit_log_days', 180);
        $bot = $this->bot([]);

        $expired = $bot->messages()->create(['telegram_message_id' => '1', 'text' => 'expired']);
        $expired->forceFill(['created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)])->saveQuietly();
        $pending = $bot->messages()->create([
            'telegram_message_id' => '2',
            'text' => 'pending deletion',
            'delete_at' => now()->subDay(),
        ]);
        $pending->forceFill(['created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)])->saveQuietly();
        $audit = AdminAuditLog::query()->create([
            'event' => 'old', 'method' => 'POST', 'path' => '/admin/old',
        ]);
        $audit->forceFill(['created_at' => now()->subDays(181), 'updated_at' => now()->subDays(181)])->saveQuietly();
        $webhook = GroupChannelWebhookUpdate::query()->create([
            'group_channel_bot_id' => $bot->id,
            'telegram_update_id' => 991,
            'payload' => ['update_id' => 991],
            'status' => GroupChannelWebhookUpdate::STATUS_DEAD,
            'attempts' => 10,
        ]);
        $webhook->forceFill(['created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)])->saveQuietly();
        $retryableWebhook = GroupChannelWebhookUpdate::query()->create([
            'group_channel_bot_id' => $bot->id,
            'telegram_update_id' => 992,
            'payload' => ['update_id' => 992],
            'status' => GroupChannelWebhookUpdate::STATUS_FAILED,
            'attempts' => 3,
            'next_attempt_at' => now()->subMinute(),
        ]);
        $retryableWebhook->forceFill([
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ])->saveQuietly();

        $this->artisan('skyguardian:data:prune')->assertSuccessful();

        $this->assertDatabaseMissing('group_channel_messages', ['id' => $expired->id]);
        $this->assertDatabaseHas('group_channel_messages', ['id' => $pending->id]);
        $this->assertDatabaseMissing('admin_audit_logs', ['id' => $audit->id]);
        $this->assertDatabaseMissing('group_channel_webhook_updates', ['id' => $webhook->id]);
        $this->assertDatabaseHas('group_channel_webhook_updates', ['id' => $retryableWebhook->id]);
    }

    public function test_webhook_secret_is_encrypted_at_rest(): void
    {
        $bot = $this->bot([]);

        $this->assertNotSame($bot->webhook_secret, DB::table('group_channel_bots')->where('id', $bot->id)->value('webhook_secret'));
    }

    private function bot(array $enabledModules): GroupChannelBot
    {
        $settings = GroupChannelBot::defaultModuleSettings();
        foreach ($enabledModules as $module) {
            $settings[$module]['enabled'] = true;
        }

        return GroupChannelBot::query()->create([
            'bot_name' => 'Safety Bot',
            'bot_token' => '123456:safety-token',
            'token_fingerprint' => hash('sha256', '123456:safety-token'),
            'webhook_secret' => str_repeat('s', 48),
            'admin_id' => '100500',
            'group_name' => 'Safety channel',
            'group_link' => 'https://t.me/safety_channel',
            'chat_type' => 'channel',
            'chat_id' => '-100500',
            'is_active' => true,
            'module_settings' => $settings,
        ]);
    }
}
