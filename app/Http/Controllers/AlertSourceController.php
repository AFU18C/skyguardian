<?php

namespace App\Http\Controllers;

use App\Models\AlertBotSetting;
use App\Models\AlertSource;
use App\Models\TechnicalTelegramAccount;
use App\Services\Telegram\TelethonAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AlertSourceController extends Controller
{
    public function index(): View
    {
        $this->importLegacySource();

        return view('alerts.sources', [
            'sources' => AlertSource::query()
                ->with(['readerAccount', 'publisherAccount'])
                ->latest('id')
                ->get(),
            'technicalAccounts' => TechnicalTelegramAccount::query()
                ->where('status', 'connected')
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateSource($request);
        AlertSource::query()->create($validated + [
            'autopublish_enabled' => false,
            'text_processing_enabled' => $request->boolean('text_processing_enabled'),
        ]);

        return back()->with('status', 'Источник добавлен. Проверьте канал и группу назначения.');
    }

    public function update(Request $request, AlertSource $alertSource): RedirectResponse
    {
        $validated = $this->validateSource($request);
        $connectionChanged = $alertSource->source_chat !== $validated['source_chat']
            || $alertSource->destination_chat !== $validated['destination_chat']
            || (int) $alertSource->reader_account_id !== (int) $validated['reader_account_id']
            || (int) $alertSource->publisher_account_id !== (int) $validated['publisher_account_id'];

        if ($request->boolean('autopublish_enabled')
            && ($alertSource->source_status !== 'available' || $alertSource->destination_status !== 'available')) {
            return back()->withErrors(['source' => 'Сначала успешно проверьте источник и группу назначения.']);
        }

        $alertSource->fill($validated + [
            'autopublish_enabled' => $request->boolean('autopublish_enabled'),
            'text_processing_enabled' => $request->boolean('text_processing_enabled'),
        ]);

        if ($connectionChanged) {
            $alertSource->source_status = 'not_checked';
            $alertSource->destination_status = 'not_checked';
            $alertSource->destination_type = null;
            $alertSource->publish_as = null;
            $alertSource->last_error = null;
        }

        $alertSource->save();

        return back()->with('status', 'Настройки источника сохранены.');
    }

    public function destroy(AlertSource $alertSource): RedirectResponse
    {
        $alertSource->delete();

        return back()->with('status', 'Источник удалён.');
    }

    public function checkSource(AlertSource $alertSource, TelethonAccountService $telethon): RedirectResponse
    {
        try {
            $result = $telethon->checkChat($alertSource->readerAccount, $alertSource->source_chat, 'source');
            $available = ($result['status'] ?? null) === 'available';
            $alertSource->update([
                'source_status' => $available ? 'available' : 'error',
                'last_error' => $available ? null : 'Технический аккаунт не может читать источник.',
                'last_checked_at' => now(),
            ]);

            return back()->with('check_modal', [
                'type' => $available ? 'success' : 'error',
                'title' => $available ? 'Источник доступен' : 'Источник недоступен',
                'message' => $available
                    ? 'Технический аккаунт может получать сообщения из «'.($result['title'] ?? $alertSource->source_chat).'».'
                    : 'Проверьте адрес канала и доступ технического аккаунта.',
            ]);
        } catch (Throwable $exception) {
            $alertSource->update([
                'source_status' => 'error',
                'last_error' => $exception->getMessage(),
                'last_checked_at' => now(),
            ]);

            return back()->with('check_modal', [
                'type' => 'error',
                'title' => 'Источник недоступен',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function checkDestination(AlertSource $alertSource, TelethonAccountService $telethon): RedirectResponse
    {
        try {
            $result = $telethon->checkChat($alertSource->publisherAccount, $alertSource->destination_chat, 'destination');
            $available = ($result['status'] ?? null) === 'available';
            $publishAs = $result['publish_as'] ?? 'account';
            $alertSource->update([
                'destination_status' => $available ? 'available' : 'error',
                'destination_type' => $result['chat_type'] ?? null,
                'publish_as' => $publishAs,
                'last_error' => $available ? null : 'Нет права отправлять сообщения в группу назначения.',
                'last_checked_at' => now(),
            ]);

            $publishLabel = match ($publishAs) {
                'channel' => 'канала',
                'group' => 'группы',
                default => 'технического аккаунта',
            };

            return back()->with('check_modal', [
                'type' => $available ? 'success' : 'error',
                'title' => $available ? 'Группа назначения доступна' : 'Нет прав на публикацию',
                'message' => $available
                    ? 'Публикация будет выполняться от имени '.$publishLabel.'.'
                    : 'Добавьте технический аккаунт в группу или канал и выдайте ему право отправлять сообщения.',
            ]);
        } catch (Throwable $exception) {
            $alertSource->update([
                'destination_status' => 'error',
                'last_error' => $exception->getMessage(),
                'last_checked_at' => now(),
            ]);

            return back()->with('check_modal', [
                'type' => 'error',
                'title' => 'Группа назначения недоступна',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function validateSource(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'source_chat' => ['required', 'string', 'max:255'],
            'destination_chat' => ['required', 'string', 'max:255'],
            'reader_account_id' => ['required', 'integer', 'exists:technical_telegram_accounts,id'],
            'publisher_account_id' => ['required', 'integer', 'exists:technical_telegram_accounts,id'],
        ]);
    }

    private function importLegacySource(): void
    {
        if (AlertSource::query()->exists()) {
            return;
        }

        $settings = AlertBotSetting::query()->first();
        $account = TechnicalTelegramAccount::query()->orderByDesc('is_primary')->orderBy('id')->first();

        if (! $settings || ! $account || ! filled($settings->source_chat) || ! filled($settings->destination_chat)) {
            return;
        }

        AlertSource::query()->create([
            'label' => 'Основной источник',
            'source_chat' => $settings->source_chat,
            'destination_chat' => $settings->destination_chat,
            'reader_account_id' => $account->id,
            'publisher_account_id' => $account->id,
            'autopublish_enabled' => (bool) $settings->autopublish_enabled,
            'text_processing_enabled' => (bool) $settings->text_processing_enabled,
            'source_status' => 'not_checked',
            'destination_status' => 'not_checked',
        ]);
    }
}