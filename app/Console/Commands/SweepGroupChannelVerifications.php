<?php

namespace App\Console\Commands;

use App\Models\GroupChannelUserState;
use App\Services\GroupChannelTelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SweepGroupChannelVerifications extends Command
{
    protected $signature = 'skyguardian:group-channel-verifications:sweep {--limit=100}';

    protected $description = 'Завершает просроченные проверки человека без нового действия пользователя';

    public function handle(GroupChannelTelegramService $telegram): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $failed = 0;

        GroupChannelUserState::query()
            ->with('bot')
            ->whereNull('verified_at')
            ->whereNotNull('verification_expires_at')
            ->where('verification_expires_at', '<=', now())
            ->oldest('verification_expires_at')
            ->limit($limit)
            ->get()
            ->each(function (GroupChannelUserState $candidate) use ($telegram, &$failed): void {
                try {
                    $state = DB::transaction(function () use ($candidate): ?GroupChannelUserState {
                        $locked = GroupChannelUserState::query()->lockForUpdate()->find($candidate->id);
                        if (! $locked
                            || $locked->verified_at
                            || ! $locked->verification_expires_at
                            || $locked->verification_expires_at->isFuture()) {
                            return null;
                        }

                        return $locked;
                    });

                    if (! $state) {
                        return;
                    }

                    $bot = $state->bot()->first();
                    if (! $bot || ! $bot->is_active || ! $bot->moduleEnabled('human_verification')) {
                        $state->update(['verification_expires_at' => null, 'verification_answer' => null]);

                        return;
                    }

                    $telegram->request($bot, 'banChatMember', [
                        'chat_id' => $bot->chat_id,
                        'user_id' => $state->telegram_user_id,
                        'revoke_messages' => true,
                    ]);

                    $state->update([
                        'verification_expires_at' => null,
                        'verification_answer' => null,
                    ]);
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error('Verification #'.$candidate->id.': '.$e->getMessage());
                }
            });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
