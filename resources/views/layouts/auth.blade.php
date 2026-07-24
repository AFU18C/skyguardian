@extends('layouts.public')

@section('body')
    <div class="auth-shell">
        <aside class="auth-aside">
            <x-brand/>
            <div class="auth-aside-copy">
                <div class="eyebrow"><span class="eyebrow-dot"></span>Панель управления</div>
                <h1>Контроль важных каналов в одном месте.</h1>
                <p>Закрытая административная часть {{ config('branding.name') }} для управления новостями и воздушными тревогами.</p>
            </div>
            <div class="signal-subtitle">{{ config('branding.domain') }}</div>
        </aside>
        <main class="auth-main">
            @yield('content')
        </main>
    </div>
@endsection
