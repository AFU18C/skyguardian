<?php

namespace App\Http\Controllers;

use App\Models\NewsSource;
use App\Models\NewsTechnicalTelegramAccount;
use App\Services\Telegram\TelethonAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class NewsSourceController extends Controller
{
    public function index(): View
    {
        return view('news.sources', [
            'sources' => NewsSource::query()->with(['readerAccount', 'publisherAccount'])->latest('id')->get(),
            'technicalAccounts' => NewsTechnicalTelegramAccount::query()
                ->where('status', 'connected')
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateSource($request);
        NewsSource::query()->create($validated + [
            'autopublish_enabled' => false,
            'text_processing_enabled' => $request->boolean('text_processing_enabled'),
        ]);

        return back()->with('status', 'Источник новостей добавлен. Запустите проверку.');
    }

    public function update(Request $request, NewsSource $newsSource): RedirectResponse
    {
        $validated = $this->validateSource($request);
        $connectionChanged = $newsSource->source_chat !== $validated['source_chat']
            || $newsSource->destination_chat !== $validated['destination_chat']
            || (int) $newsSource->reader_account_id !== (int) $validated['reader_account_id']
            || (int) $newsSource->publisher_account_id !== (int) $validated['publisher_account_id'];

        if ($request->boolean('autopublish_enabled')
            && ($connectionChanged || $newsSource->source_status !== 'available' || $newsSource->destination_status !== 'available')) {
            return back()->withErrors(['source' => 'Сначала сохраните изменения и успешно запустите проверку.']);
        }

        $newsSource->fill($validated + [
            'autopublish_enabled' => $request->boolean('autopublish_enabled'),
            'text_processing_enabled' => $request->boolean('text_processing_enabled'),
        ]);

        if ($connectionChanged) {
            $newsSource->source_status = 'not_checked';
            $newsSource->destination_status = 'not_checked';
            $newsSource->destination_type = null;
            $newsSource->publish_as = null;
            $newsSource->last_error = null;
            $newsSource->autopublish_enabled = false;
            $newsSource->last_polled_at = null;
        }

        $newsSource->save();

        return back()->with('status', 'Настройки источника новостей сохранены.');
    }

    public function destroy(NewsSource $newsSource): RedirectResponse
    {
        $newsSource->delete();
        return back()->with('status', 'Источник новостей удалён.');
    }

    public function check(NewsSource $newsSource, TelethonAccountService $telethon): RedirectResponse
    {
        try {
            if (! $newsSource->readerAccount || ! $newsSource->publisherAccount) {
                throw new \RuntimeException('Выберите отдельные технические аккаунты новостного раздела.');
            }

            $sourceResult = $telethon->checkChat($newsSource->readerAccount, $newsSource->source_chat, 'source');
            $destinationResult = $telethon->checkChat($newsSource->publisherAccount, $newsSource->destination_chat, 'destination');
            $sourceAvailable = ($sourceResult['status'] ?? null) === 'available';
            $destinationAvailable = ($destinationResult['status'] ?? null) === 'available';
            $publishAs = $destinationResult['publish_as'] ?? 'account';

            $newsSource->update([
                'source_status' => $sourceAvailable ? 'available' : 'error',
                'destination_status' => $destinationAvailable ? 'available' : 'error',
                'destination_type' => $destinationResult['chat_type'] ?? null,
                'publish_as' => $publishAs,
                'last_error' => $sourceAvailable && $destinationAvailable ? null : 'Проверка источника или назначения завершилась ошибкой.',
                'last_checked_at' => now(),
            ]);

            return back()->with('check_modal', [
                'type' => $sourceAvailable && $destinationAvailable ? 'success' : 'error',
                'title' => $sourceAvailable && $destinationAvailable ? 'Проверка успешно пройдена' : 'Проверка не пройдена',
                'message' => $sourceAvailable && $destinationAvailable
                    ? 'Новостной источник и группа назначения доступны.'
                    : 'Проверьте адреса, доступ аккаунтов и права публикации.',
            ]);
        } catch (Throwable $exception) {
            $newsSource->update(['last_error' => $exception->getMessage(), 'last_checked_at' => now()]);
            return back()->with('check_modal', ['type' => 'error', 'title' => 'Ошибка проверки', 'message' => $exception->getMessage()]);
        }
    }

    private function validateSource(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'source_chat' => ['required', 'string', 'max:255'],
            'destination_chat' => ['required', 'string', 'max:255'],
            'reader_account_id' => ['required', 'integer', 'exists:news_technical_telegram_accounts,id'],
            'publisher_account_id' => ['required', 'integer', 'exists:news_technical_telegram_accounts,id'],
            'poll_interval_seconds' => ['required', 'integer', 'min:3', 'max:43200'],
        ]);
    }
}
