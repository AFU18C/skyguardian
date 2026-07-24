@extends('layouts.admin')

@section('title', 'Настройка')
@section('section', 'Новости')
@section('page-title', 'Настройка')

@section('content')
<div>
    <div class="content-heading">
        <div>
            <h1>Настройка</h1>
            <p>Telegram API и технические аккаунты новостей.</p>
        </div>
        <x-ui.button
            variant="primary"
            :href="route('news.settings.create')"
        >
            <x-icon name="plus" :size="15"/>
            Добавить
        </x-ui.button>
    </div>

    <section class="panel">
        <header class="panel-header">
            <div>
                <div class="panel-title">Telegram API и технические аккаунты</div>
                <div class="panel-copy">Настройки раздела «Новости».</div>
            </div>
        </header>
        <x-ui.empty-state
            title="API и технические аккаунты ещё не добавлены"
            description="Нажмите «Добавить», чтобы перейти к форме."
            icon="settings"
        />
    </section>
</div>
@endsection
