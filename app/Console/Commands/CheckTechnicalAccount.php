<?php

namespace App\Console\Commands;

use App\Models\TechnicalAccount;
use App\Services\TechnicalAccountService;
use Illuminate\Console\Command;
use Throwable;

class CheckTechnicalAccount extends Command
{
    protected $signature = 'skyguardian:account:check {account : ID технического аккаунта}';

    protected $description = 'Выполнить ручную проверку технического Telegram-аккаунта';

    public function handle(TechnicalAccountService $service): int
    {
        $account = TechnicalAccount::query()->findOrFail((int) $this->argument('account'));

        try {
            $service->manualCheck($account);
            $this->info('Технический аккаунт успешно проверен.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
