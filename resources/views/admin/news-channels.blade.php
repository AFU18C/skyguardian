@extends('layouts.admin')

@section('title', 'Каналы данных')
@section('section', 'Новости')
@section('page-title', 'Каналы данных')

@section('content')
<div>
    <div class="content-heading">
        <div>
            <h1>Каналы данных</h1>
            <p>Источники сообщений и каналы публикации новостей.</p>
        </div>
        <x-ui.button
            variant="primary"
            :href="route('news.channels.create')"
        >
            <x-icon name="plus" :size="15"/>
            Добавить
        </x-ui.button>
    </div>

    <section class="panel">
        <header class="panel-header">
            <div>
                <div class="panel-title">Каналы данных новостей</div>
                <div class="panel-copy">Настройки источников и публикации раздела «Новости».</div>
            </div>
        </header>
        <x-ui.empty-state
            title="Каналы данных ещё не добавлены"
            description="Нажмите «Добавить», чтобы перейти к форме."
            icon="channels"
        />
    </section>
</div>
@endsection
