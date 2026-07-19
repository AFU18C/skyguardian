<?php

namespace App\Http\Controllers;

use App\Models\AlertSource;
use App\Models\NewsSource;
use App\Models\NewsTechnicalTelegramAccount;
use App\Models\NewsTelegramApiCredential;
use App\Models\TechnicalTelegramAccount;
use App\Models\TelegramApiCredential;
use App\Services\Telegram\TelethonAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TelegramDependencyController extends Controller
{
    public function destroyAlertApi(
        Request $request,
        TelegramApiCredential $telegramApi,
        TelethonAccountService $telethon,
    ): RedirectResponse {
        $wasPrimary = $telegramApi->is_primary;
        $accounts = $telegramApi->technicalAccounts()->get();

        foreach ($accounts as $account) {
            $telethon->resetSession($account);
        }

        DB::transaction(function () use ($telegramApi, $accounts): void {
            $this->stopAlertSources($accounts, 'Telegram API удалён. Выберите новый API и переподключите технический аккаунт.');

            TechnicalTelegramAccount::query()
                ->whereIn('id', $this->accountIds($accounts))
                ->update([
                    'telegram_api_credential_id' => null,
                    'status' => 'disconnected',
                    'last_error' => 'Telegram API удалён. Выберите новый API и подключите аккаунт заново.',
                    'last_checked_at' => now(),
                ]);

            $telegramApi->delete();
        });

        $request->session()->forget('telegram_auth');

        if ($wasPrimary) {
            TelegramApiCredential::query()->orderBy('id')->first()?->update(['is_primary' => true]);
        }

        return back()->with('status', 'Telegram API удалён. Зависимые цепочки остановлены, аккаунты и источники сохранены.');
    }

    public function destroyNewsApi(
        Request $request,
        NewsTelegramApiCredential $newsTelegramApi,
        TelethonAccountService $telethon,
    ): RedirectResponse {
        $wasPrimary = $newsTelegramApi->is_primary;
        $accounts = $newsTelegramApi->technicalAccounts()->get();

        foreach ($accounts as $account) {
            $telethon->resetSession($account);
        }

        DB::transaction(function () use ($newsTelegramApi, $accounts): void {
            $this->stopNewsSources($accounts, 'Telegram API удалён. Выберите новый API и переподключите технический аккаунт.');

            NewsTechnicalTelegramAccount::query()
                ->whereIn('id', $this->accountIds($accounts))
                ->update([
                    'news_telegram_api_credential_id' => null,
                    'status' => 'disconnected',
                    'last_error' => 'Telegram API удалён. Выберите новый API и подключите аккаунт заново.',
                    'last_checked_at' => now(),
                ]);

            $newsTelegramApi->delete();
        });

        $request->session()->forget('news_telegram_auth');

        if ($wasPrimary) {
            NewsTelegramApiCredential::query()->orderBy('id')->first()?->update(['is_primary' => true]);
        }

        return back()->with('status', 'Telegram API новостей удалён. Зависимые цепочки остановлены, аккаунты и источники сохранены.');
    }

    public function destroyAlertAccount(
        Request $request,
        TechnicalTelegramAccount $account,
        TelethonAccountService $telethon,
    ): RedirectResponse {
        $wasPrimary = $account->is_primary;
        $telethon->resetSession($account);

        DB::transaction(function () use ($account): void {
            $this->stopAlertSources(collect([$account]), 'Технический аккаунт удалён. Выберите новый аккаунт.');
            $account->delete();
        });

        $request->session()->forget('telegram_auth');

        if ($wasPrimary) {
            TechnicalTelegramAccount::query()->orderBy('id')->first()?->update(['is_primary' => true]);
        }

        return back()->with('status', 'Технический аккаунт удалён. Зависимые цепочки остановлены, источники сохранены.');
    }

    public function destroyNewsAccount(
        Request $request,
        NewsTechnicalTelegramAccount $newsAccount,
        TelethonAccountService $telethon,
    ): RedirectResponse {
        $wasPrimary = $newsAccount->is_primary;
        $telethon->resetSession($newsAccount);

        DB::transaction(function () use ($newsAccount): void {
            $this->stopNewsSources(collect([$newsAccount]), 'Технический аккаунт удалён. Выберите новый аккаунт.');
            $newsAccount->delete();
        });

        $request->session()->forget('news_telegram_auth');

        if ($wasPrimary) {
            NewsTechnicalTelegramAccount::query()->orderBy('id')->first()?->update(['is_primary' => true]);
        }

        return back()->with('status', 'Технический аккаунт новостей удалён. Зависимые цепочки остановлены, источники сохранены.');
    }

    private function stopAlertSources(Collection $accounts, string $message): void
    {
        $ids = $this->accountIds($accounts);
        if ($ids === []) {
            return;
        }

        AlertSource::query()
            ->where(function ($query) use ($ids): void {
                $query->whereIn('reader_account_id', $ids)
                    ->orWhereIn('publisher_account_id', $ids);
            })
            ->update([
                'autopublish_enabled' => false,
                'source_status' => 'not_checked',
                'destination_status' => 'not_checked',
                'last_error' => $message,
            ]);
    }

    private function stopNewsSources(Collection $accounts, string $message): void
    {
        $ids = $this->accountIds($accounts);
        if ($ids === []) {
            return;
        }

        NewsSource::query()
            ->where(function ($query) use ($ids): void {
                $query->whereIn('reader_account_id', $ids)
                    ->orWhereIn('publisher_account_id', $ids);
            })
            ->update([
                'autopublish_enabled' => false,
                'source_status' => 'not_checked',
                'destination_status' => 'not_checked',
                'last_error' => $message,
            ]);
    }

    private function accountIds(Collection $accounts): array
    {
        return $accounts
            ->pluck('id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
