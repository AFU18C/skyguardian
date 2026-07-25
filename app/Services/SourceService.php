<?php

namespace App\Services;

use App\Models\Source;
use RuntimeException;
use Throwable;

class SourceService
{
    public function __construct(
        private readonly TelethonClient $telethon,
        private readonly OperationGate $gate,
    ) {}

    public function manualCheck(Source $source): Source
    {
        $account = $source->technicalAccount;

        if (! $account || ! $account->is_active) {
            throw new RuntimeException('Для источника не назначен активный технический аккаунт.');
        }

        $token = $this->gate->acquire('source.manual-check', $account);

        try {
            $this->telethon->call('check_peer', $account, ['peer' => $source->source_peer]);

            $source->forceFill([
                'status' => 'available',
                'last_error' => null,
                'last_manual_check_at' => now(),
                'last_success_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $source->forceFill([
                'status' => 'error',
                'last_error' => $e->getMessage(),
                'last_manual_check_at' => now(),
            ])->save();

            throw $e;
        } finally {
            $this->gate->release($token);
        }

        return $source->refresh();
    }
}
