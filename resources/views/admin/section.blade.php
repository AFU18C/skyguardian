@extends('layouts.admin')

@section('title', $title)
@section('section', $group)
@section('page-title', $title)

@section('content')
<div class="content-heading">
    <div>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
    </div>
    <x-ui.button variant="primary" disabled title="Форма будет подключена на следующем этапе">
        <x-icon name="plus" :size="15"/>
        Добавить
    </x-ui.button>
</div>

<section class="panel">
    <header class="panel-header">
        <div>
            <div class="panel-title">{{ $group }} · {{ $title }}</div>
            <div class="panel-copy">Шаблон раздела готов к подключению функционала.</div>
        </div>
    </header>
    <x-ui.empty-state
        title="Данные ещё не добавлены"
        description="Форма добавления и рабочая логика будут подключены на следующем этапе."
        :icon="$title === 'Каналы данных' ? 'channels' : 'settings'"
    />
</section>
@endsection
