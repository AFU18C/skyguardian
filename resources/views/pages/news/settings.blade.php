@extends('layouts.admin')

@section('title', 'Настройки новостей — SkyGuardian')
@section('section', 'Новости')
@section('heading', 'Настройки')

@section('content')
    <section class="panel">
        <form class="settings-form" onsubmit="return false;">
            <label>
                <span>Telegram Bot Token</span>
                <input type="password" autocomplete="off" disabled>
            </label>

            <label class="toggle-row">
                <span>Включить / выключить бота</span>
                <input type="checkbox" disabled>
            </label>
        </form>
    </section>
@endsection
