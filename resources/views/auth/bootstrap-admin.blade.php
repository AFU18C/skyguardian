@extends('layouts.auth')

@section('title', 'Создание администратора')

@section('content')
    <div class="space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-400">SkyGuardian</p>
            <h1 class="mt-2 text-2xl font-semibold text-white">Создание администратора</h1>
        </div>

        <form method="POST" action="{{ route('bootstrap-admin.store', ['token' => $token]) }}" class="space-y-4">
            @csrf

            <label class="block">
                <span class="mb-2 block text-sm text-slate-300">Имя</span>
                <input name="name" value="{{ old('name') }}" required autocomplete="name"
                    class="w-full rounded-xl border border-slate-700 bg-slate-900/80 px-4 py-3 text-white outline-none focus:border-sky-500">
            </label>

            <label class="block">
                <span class="mb-2 block text-sm text-slate-300">Email</span>
                <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"
                    class="w-full rounded-xl border border-slate-700 bg-slate-900/80 px-4 py-3 text-white outline-none focus:border-sky-500">
            </label>

            <label class="block">
                <span class="mb-2 block text-sm text-slate-300">Пароль</span>
                <input type="password" name="password" required minlength="10" autocomplete="new-password"
                    class="w-full rounded-xl border border-slate-700 bg-slate-900/80 px-4 py-3 text-white outline-none focus:border-sky-500">
            </label>

            <button type="submit"
                class="w-full rounded-xl bg-sky-500 px-4 py-3 font-semibold text-slate-950 transition hover:bg-sky-400">
                Создать администратора
            </button>
        </form>
    </div>
@endsection
