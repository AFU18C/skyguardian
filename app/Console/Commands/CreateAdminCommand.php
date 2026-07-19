<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin {email} {--name=Администратор} {--password=}';

    protected $description = 'Create or update the SkyGuardian administrator account';

    public function handle(): int
    {
        $password = (string) ($this->option('password') ?: $this->secret('Пароль администратора'));

        if (mb_strlen($password) < 8) {
            $this->error('Пароль должен содержать минимум 8 символов.');
            return self::FAILURE;
        }

        User::query()->updateOrCreate(
            ['email' => (string) $this->argument('email')],
            [
                'name' => (string) $this->option('name'),
                'password' => Hash::make($password),
            ]
        );

        $this->info('Администратор создан.');
        return self::SUCCESS;
    }
}
