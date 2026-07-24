@extends('layouts.admin')

@section('title', 'Настройка')
@section('section', 'Новости')
@section('page-title', 'Настройка')

@section('content')
<section class="panel empty-state">
    <div>
        <div class="empty-icon"><x-icon name="settings" :size="24"/></div>
        <div class="empty-title">Данные ещё не добавлены</div>
        <p class="empty-copy">Форма добавления и рабочая логика будут подключены на следующем этапе.</p>
    </div>
</section>
@endsection
