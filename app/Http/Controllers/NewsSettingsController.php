<?php

namespace App\Http\Controllers;

use App\Models\TelegramAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NewsSettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.news-settings', [
            'accounts' => TelegramAccount::query()->forPurpose('news')->latest()->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.news-setting-form', [
            'editing' => false,
            'account' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(TelegramAccount::query()->forPurpose('news')->count() >= 10, 422, 'Можно добавить не более 10 Telegram API.');

        $data = $this->validated($request);
        $data['purpose'] = 'news';
        $data['status'] = 'not_connected';

        TelegramAccount::query()->create($data);

        return redirect()->route('news.settings')->with('status', 'Telegram API добавлен.');
    }

    public function edit(TelegramAccount $account): View
    {
        $this->ensureNewsAccount($account);

        return view('admin.news-setting-form', [
            'editing' => true,
            'account' => $account,
        ]);
    }

    public function update(Request $request, TelegramAccount $account): RedirectResponse
    {
        $this->ensureNewsAccount($account);

        $data = $this->validated($request, true);

        if (blank($data['api_hash'] ?? null)) {
            unset($data['api_hash']);
        }

        $account->update($data);

        return redirect()->route('news.settings')->with('status', 'Настройка сохранена.');
    }

    public function toggle(TelegramAccount $account): RedirectResponse
    {
        $this->ensureNewsAccount($account);

        if ($account->status !== 'disabled') {
            $account->update(['status' => 'disabled']);
        } elseif ($account->connected_at && File::exists($account->sessionPath())) {
            $account->update(['status' => 'connected', 'last_error' => null]);
        } else {
            $account->update([
                'status' => 'error',
                'last_error' => 'Технический аккаунт нужно подключить к Telegram.',
            ]);
        }

        return redirect()->route('news.settings')->with('status', 'Статус настройки изменён.');
    }

    public function destroy(TelegramAccount $account): RedirectResponse
    {
        $this->ensureNewsAccount($account);

        File::deleteDirectory(dirname($account->sessionPath()));
        $account->delete();

        return redirect()->route('news.settings')->with('status', 'Настройка удалена.');
    }

    private function validated(Request $request, bool $editing = false): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'api_id' => ['required', 'digits_between:4,12'],
            'api_hash' => [$editing ? 'nullable' : 'required', 'string', 'size:32'],
            'login_method' => ['required', Rule::in(['phone', 'qr'])],
            'phone' => ['nullable', 'string', 'max:30', 'required_if:login_method,phone'],
        ]);
    }

    private function ensureNewsAccount(TelegramAccount $account): void
    {
        abort_unless($account->purpose === 'news', 404);
    }
}
