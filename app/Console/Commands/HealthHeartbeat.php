<?php

namespace App\Console\Commands;

use App\Services\HealthCheckService;
use Illuminate\Console\Command;

class HealthHeartbeat extends Command
{
    protected $signature = 'skyguardian:health:heartbeat';

    protected $description = 'Record the scheduler heartbeat used by readiness checks';

    public function handle(HealthCheckService $health): int
    {
        $health->heartbeat();

        return self::SUCCESS;
    }
}
