<?php

namespace App\Services;

use App\Models\TechnicalAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OperationGate
{
    private const MYSQL_LOCK = 'skyguardian-operation-gate';

    public function acquire(string $operation, ?TechnicalAccount $account = null, int $ttlSeconds = 120): string
    {
        $usesMysqlLock = DB::connection()->getDriverName() === 'mysql';

        if ($usesMysqlLock) {
            $result = DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', [self::MYSQL_LOCK]);
            if ((int) ($result->acquired ?? 0) !== 1) {
                throw new RuntimeException('Не удалось получить блокировку операций.');
            }
        }

        try {
            return DB::transaction(function () use ($operation, $account, $ttlSeconds) {
                DB::table('operation_locks')->where('expires_at', '<=', now())->delete();

                $global = DB::table('operation_locks')->count();
                if ($global >= config('skyguardian.limits.global_concurrent_operations', 5)) {
                    throw new RuntimeException('Достигнут общий лимит параллельных операций.');
                }

                if ($account) {
                    $perAccount = DB::table('operation_locks')
                        ->where('technical_account_id', $account->id)
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
                    'expires_at' => now()->addSeconds(max(1, $ttlSeconds)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $token;
            });
        } finally {
            if ($usesMysqlLock) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::MYSQL_LOCK]);
            }
        }
    }

    public function release(string $token): void
    {
        DB::table('operation_locks')->where('token', $token)->delete();
    }
}