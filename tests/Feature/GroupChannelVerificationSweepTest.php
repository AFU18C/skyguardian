<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\GroupChannelUserState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GroupChannelVerificationSweepTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function expired_verification_is_enforced_without_another_user_update(): void
    {
        $settings = GroupChannelBot::defaultModuleSettings();
        $settings['human_verification']['enabled'] = true;
        $token = '123456:verification-sweep-token';
        $bot = GroupChannelBot::query()->create([
            'bot_name' => 'Verification Bot',
            'bot_token' => $token,
            'token_fingerprint' => hash('sha256', $token),
            'webhook_secret' => str_repeat('b', 48),
            'admin_id' => '100500',
            'group_name' => 'Verification group',
            'group_link' => 'https://t.me/verification_group',
            'chat_type' => 'supergroup',
            'chat_id' => '-100600',
            'is_active' => true,
            'module_settings' => $settings,
        ]);
        $state = GroupChannelUserState::query()->create([
            'group_channel_bot_id' => $bot->id,
            'telegram_user_id' => '700',
            'joined_at' => now()->subMinutes(10),
            'verified_at' => null,
            'verification_answer' => '4',
            'verification_expires_at' => now()->subMinute(),
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

        $this->artisan('skyguardian:group-channel-verifications:sweep --limit=100')
            ->assertSuccessful();

        $state->refresh();
        $this->assertNull($state->verified_at);
        $this->assertNull($state->verification_answer);
        $this->assertNull($state->verification_expires_at);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/banChatMember')
            && (string) $request['user_id'] === '700');
    }
}
