<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BootstrapAdminController extends Controller
{
    private const TOKEN_HASH = '822a96fb606441235b84ba9d198f3164f3e9958ac19b5bd436d879cc8be6bffb';

    private function authorizeToken(string $token): void
    {
        if (
            File::exists(storage_path('app/admin-bootstrap.lock'))
            || ! hash_equals(self::TOKEN_HASH, hash('sha256', $token))
        ) {
            throw new NotFoundHttpException();
        }
    }

    public function create(string $token): View
    {
        $this->authorizeToken($token);

        return view('auth.bootstrap-admin', ['token' => $token]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $this->authorizeToken($token);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:10'],
        ]);

        try {
            User::query()->updateOrCreate(
                ['email' => $validated['email']],
                [
                    'name' => $validated['name'],
                    'password' => Hash::make($validated['password']),
                ],
            );

            File::put(storage_path('app/admin-bootstrap.lock'), now()->toIso8601String());
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withErrors(['setup' => 'Не удалось создать администратора. Повторите попытку.'])
                ->onlyInput('name', 'email');
        }

        return redirect()->route('login')->with('status', 'Администратор создан.');
    }
}
