<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('admin-login:ip:'.hash('sha256', '127.0.0.1'));
    }

    #[Test]
    public function changing_email_does_not_bypass_the_ip_wide_login_limiter(): void
    {
        for ($attempt = 1; $attempt <= 15; $attempt++) {
            $this->post(route('admin.login.store'), [
                'email' => "unknown{$attempt}@example.com",
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $response = $this->post(route('admin.login.store'), [
            'email' => 'another@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'с этого адреса',
            (string) session('errors')?->first('email'),
        );
    }

    #[Test]
    public function mutating_admin_routes_are_audited_without_request_payloads(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.betting.search'), [
            'search_mode' => 'websites',
            'password' => 'must-never-be-logged',
        ])->assertRedirect();

        $this->assertDatabaseHas('admin_audit_logs', [
            'user_id' => $user->id,
            'route_name' => 'admin.betting.search',
            'method' => 'POST',
            'status_code' => 302,
        ]);

        $this->assertStringNotContainsString(
            'must-never-be-logged',
            (string) AdminAuditLog::query()->firstOrFail()->toJson(),
        );
    }
}
