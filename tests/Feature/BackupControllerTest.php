<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BackupControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_or_start_backup(): void
    {
        $this->get(route('admin.system.backup.show'))
            ->assertRedirect(route('admin.login'));
        $this->post(route('admin.system.backup.store'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_read_backup_status(): void
    {
        $payload = [
            'state' => 'success',
            'started_at' => '2026-08-02T06:00:00+00:00',
            'finished_at' => '2026-08-02T06:01:00+00:00',
            'last_backup_at' => '2026-08-02T06:01:00+00:00',
            'size_bytes' => 123456,
            'message' => 'Последняя копия создана успешно',
        ];

        $this->mock(BackupService::class, function (MockInterface $mock) use ($payload): void {
            $mock->shouldReceive('status')->once()->andReturn($payload);
        });

        $this->actingAs(User::factory()->create())
            ->getJson(route('admin.system.backup.show'))
            ->assertOk()
            ->assertExactJson($payload);
    }

    public function test_authenticated_admin_can_start_backup(): void
    {
        $this->mock(BackupService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')->once()->andReturn(true);
        });

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.system.backup.store'))
            ->assertAccepted()
            ->assertJson([
                'state' => 'running',
                'message' => 'Создание резервной копии запущено.',
            ]);
    }

    public function test_running_backup_is_not_started_twice(): void
    {
        $this->mock(BackupService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')->once()->andReturn(false);
        });

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.system.backup.store'))
            ->assertAccepted()
            ->assertJson([
                'state' => 'running',
                'message' => 'Резервная копия уже создаётся.',
            ]);
    }

    public function test_start_error_is_returned_without_exposing_server_details(): void
    {
        $this->mock(BackupService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->andThrow(new RuntimeException('sensitive systemctl output'));
        });

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.system.backup.store'))
            ->assertServiceUnavailable()
            ->assertExactJson([
                'message' => 'Не удалось запустить резервное копирование.',
            ]);
    }
}
