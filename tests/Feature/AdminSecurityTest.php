<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\SitePage;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function administrator_can_enable_mfa_and_recovery_codes_are_only_stored_as_hashes(): void
    {
        $user = User::factory()->create();
        $totp = Mockery::mock(TotpService::class);
        $totp->shouldReceive('generateSecret')->once()->andReturn('TESTSECRET234567');
        $totp->shouldReceive('recoveryCodes')->once()->andReturn(['AAAAA-BBBBB', 'CCCCC-DDDDD', 'EEEEE-FFFFF', 'GGGGG-HHHHH', 'IIIII-JJJJJ']);
        $totp->shouldReceive('verify')->once()->with('TESTSECRET234567', '123456')->andReturnTrue();
        $this->app->instance(TotpService::class, $totp);

        $this->actingAs($user)
            ->post(route('admin.security.mfa.begin'), ['password' => 'password'])
            ->assertRedirect();
        $this->actingAs($user)
            ->withSession([
                'mfa.setup.secret' => 'TESTSECRET234567',
                'mfa.setup.recovery_codes' => ['AAAAA-BBBBB', 'CCCCC-DDDDD', 'EEEEE-FFFFF', 'GGGGG-HHHHH', 'IIIII-JJJJJ'],
            ])
            ->post(route('admin.security.mfa.enable'), ['code' => '123456'])
            ->assertRedirect()
            ->assertSessionHas('mfa_recovery_codes');

        $user->refresh();
        $this->assertTrue($user->mfaEnabled());
        $this->assertNotSame('TESTSECRET234567', DB::table('users')->where('id', $user->id)->value('mfa_secret'));
        $this->assertNotContains('AAAAA-BBBBB', $user->mfa_recovery_codes);
        $this->assertTrue(Hash::check('AAAAA-BBBBB', $user->mfa_recovery_codes[0]));
    }

    #[Test]
    public function login_requires_second_factor_and_records_successful_challenge(): void
    {
        $user = User::factory()->create([
            'mfa_secret' => 'TESTSECRET234567',
            'mfa_recovery_codes' => [],
            'mfa_enabled_at' => now(),
        ]);
        $totp = Mockery::mock(TotpService::class);
        $totp->shouldReceive('verify')->once()->with('TESTSECRET234567', '123456')->andReturnTrue();
        $this->app->instance(TotpService::class, $totp);

        $this->post(route('admin.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.mfa.challenge'));
        $this->assertGuest();

        $this->post(route('admin.mfa.challenge.store'), ['code' => '123456'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('admin_audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.mfa.accepted',
            'response_status' => 302,
        ]);
    }

    #[Test]
    public function viewer_is_read_only_but_can_log_out_and_admin_actions_are_audited(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $page = SitePage::query()->where('system_key', 'home')->firstOrFail();

        $this->actingAs($viewer)->get(route('admin.site-settings'))->assertOk();
        $this->actingAs($viewer)->delete(route('admin.site-settings.pages.destroy', $page))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.logout'))->assertRedirect(route('admin.login'));

        $administrator = User::factory()->create();
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $this->actingAs($administrator)->put(route('admin.security.users.role', $operator), [
            'role' => User::ROLE_VIEWER,
        ])->assertRedirect();

        $this->assertSame(User::ROLE_VIEWER, $operator->fresh()->role);
        $this->assertTrue(AdminAuditLog::query()
            ->where('event', 'admin.security.users.role')
            ->where('user_id', $administrator->id)
            ->exists());
    }

    #[Test]
    public function last_administrator_cannot_remove_their_own_role(): void
    {
        $administrator = User::factory()->create();

        $this->actingAs($administrator)->put(route('admin.security.users.role', $administrator), [
            'role' => User::ROLE_OPERATOR,
        ])->assertSessionHasErrors('role');

        $this->assertSame(User::ROLE_ADMINISTRATOR, $administrator->fresh()->role);
    }
}
