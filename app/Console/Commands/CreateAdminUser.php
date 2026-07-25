<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'skyguardian:admin:create
        {--name= : Имя администратора}
        {--email= : Email администратора}
        {--password= : Пароль администратора}';

    protected $description = 'Создать или обновить администратора SkyGuardian';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Имя администратора', 'Администратор'));
        $email = (string) ($this->option('email') ?: $this->ask('Email администратора'));
        $password = (string) ($this->option('password') ?: $this->secret('Пароль администратора'));

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', Password::min(10)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::query()->updateOrCreate(
            ['email' => mb_strtolower($email)],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        $this->info('Администратор создан или обновлён.');

        return self::SUCCESS;
    }
}
