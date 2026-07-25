<?php

namespace App\Services;

use App\Models\TechnicalAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OperationGate
{
    public function acquire(string $operation, ?TechnicalAccount $account = null, int $ttlSeconds = 120): string
    {
        return DB::transaction(function () use ($operation, $account, $ttlSeconds) {
            DB::table('operation_locks')->where('expires_at', '<=', now())->delete();

            $global = DB::table('operation_locks')->lockForUpdate()->count();
            if ($global >= config('skyguardian.limits.global_concurrent_operations', 5)) {
                throw new RuntimeException('Достигнут общий лимит параллельных операций.');
            }

            if ($account) {
                $perAccount = DB::table('operation_locks')
                    ->where('technical_account_id', $account->id)
                    ->lockForUpdate()
                    ->count();

                if ($perAccount >= config('skyguardian.limits.account_concurrent_operations', 2)) {
                    throw new RuntimeException('Достигнут лимит операций для технического аккаунта.');
                }
            }

            $token = (string) Str::uuid();
            DB::table('operation_locks')->insert([
                'token' => $token,
                'technical_account_id' => $account?->id,
                'operation' => $operation,
                'expires_at' => now()->addSeconds($ttlSeconds),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $token;
        });
    }

    public function release(string $token): void
    {
        DB::table('operation_locks')->where('token', $token)->delete();
    }
}
