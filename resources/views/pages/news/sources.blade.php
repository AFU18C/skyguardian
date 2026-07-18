@extends('layouts.admin')

@section('title', 'Источники новостей — SkyGuardian')
@section('section', 'Новости')
@section('heading', 'Источники')

@section('content')
    <div class="page-actions">
        <a class="button button-primary" href="{{ route('news.sources.create') }}">
            Добавить источник
        </a>
    </div>

    <section class="panel">
        <div class="source-list" aria-label="Список источников">
            <p class="empty-text">Источники ещё не добавлены.</p>
        </div>

        <div class="source-actions" hidden aria-label="Действия с выбранным источником">
            <button type="button">Открыть</button>
            <button type="button">Проверить сейчас</button>
            <button type="button">Редактировать</button>
            <button type="button" class="danger">Удалить</button>
        </div>
    </section>
@endsection
