@extends('layouts.auth')

@section('title', 'Авторизация — '.config('branding.name'))

@section('content')
<section class="auth-card">
    <div class="eyebrow"><span class="eyebrow-dot"></span>Защищённый вход</div>
    <h2>Добро пожаловать</h2>
    <p>Введите данные администратора, чтобы открыть панель управления.</p>

    @if($errors->any())
        <x-ui.alert class="mb-5">{{ $errors->first() }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="form-stack">
        @csrf
        <label class="field">
            <span class="field-label">Email</span>
            <input class="input" type="email" name="email" value="{{ old('email') }}"
                   autocomplete="email" placeholder="admin@skyguardian.pp.ua" required autofocus>
        </label>

        <label class="field">
            <span class="field-label">Пароль</span>
            <input class="input" type="password" name="password" autocomplete="current-password"
                   placeholder="Введите пароль" required>
        </label>

        <label class="checkbox-row">
            <input class="checkbox" type="checkbox" name="remember" value="1">
            Запомнить меня
        </label>

        <x-ui.button type="submit" variant="primary" class="w-full">
            Войти в систему
            <x-icon name="chevron" :size="15"/>
        </x-ui.button>
    </form>

    <div class="mt-7 text-center text-xs text-slate-600">
        <a class="text-slate-500 no-underline hover:text-slate-300" href="{{ route('home') }}">Вернуться на сайт</a>
    </div>
</section>
@endsection
