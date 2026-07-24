<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    protected $signature = 'app:create-admin {--name=} {--email=} {--password=}';

    protected $description = 'Create or update the SkyGuardian administrator account';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Имя администратора', 'Administrator');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: $this->secret('Пароль');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Указан неверный email.');

            return self::FAILURE;
        }

        if (! is_string($password) || mb_strlen($password) < 10) {
            $this->error('Пароль должен содержать минимум 10 символов.');

            return self::FAILURE;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        $this->info('Администратор SkyGuardian сохранён.');

        return self::SUCCESS;
    }
}
