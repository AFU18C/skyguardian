<?php

namespace App\Http\Controllers;

use App\Models\TelegramAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(): View
    {
        $requiredExtensions = ['mbstring', 'xml', 'json', 'fileinfo', 'gmp', 'openssl', 'iconv', 'gd'];
        $extensions = collect($requiredExtensions)
            ->mapWithKeys(fn (string $extension): array => [$extension => extension_loaded($extension)]);

        return view('integrations.index', [
            'madelineInstalled' => class_exists(\danog\MadelineProto\API::class),
            'extensions' => $extensions,
            'requirementsReady' => $extensions->every(fn (bool $loaded): bool => $loaded),
            'accounts' => TelegramAccount::query()->latest()->get(),
            'accountsLimit' => 10,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(TelegramAccount::query()->count() >= 10, 422, 'Можно добавить не более 10 Telegram API.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'api_id' => ['required', 'digits_between:4,12'],
            'api_hash' => ['required', 'string', 'size:32'],
            'login_method' => ['required', Rule::in(['phone', 'qr'])],
            'phone' => ['nullable', 'string', 'max:30', 'required_if:login_method,phone'],
        ]);

        $data['status'] = 'not_connected';
        TelegramAccount::query()->create($data);

        return back()->with('status', 'Telegram API добавлен. Теперь можно начать подключение аккаунта.');
    }

    public function update(Request $request, TelegramAccount $telegramAccount): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'api_id' => ['required', 'digits_between:4,12'],
            'api_hash' => ['nullable', 'string', 'size:32'],
            'login_method' => ['required', Rule::in(['phone', 'qr'])],
            'phone' => ['nullable', 'string', 'max:30', 'required_if:login_method,phone'],
        ]);

        if (blank($data['api_hash'] ?? null)) {
            unset($data['api_hash']);
        }

        $telegramAccount->update($data);

        return back()->with('status', 'Настройки Telegram API обновлены.');
    }

    public function destroy(TelegramAccount $telegramAccount): RedirectResponse
    {
        File::deleteDirectory(dirname($telegramAccount->sessionPath()));
        $telegramAccount->delete();

        return back()->with('status', 'Telegram API и локальная сессия удалены.');
    }
}
