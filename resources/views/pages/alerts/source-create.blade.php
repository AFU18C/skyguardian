@extends('layouts.admin')

@section('title', 'Добавить источник — SkyGuardian')
@section('section', 'Воздушная тревога')
@section('heading', 'Добавить источник')

@section('content')
    @include('pages.alerts.source-form', [
        'action' => route('alerts.sources.store'),
        'method' => 'POST',
        'source' => null,
        'submitLabel' => 'Сохранить',
    ])
@endsection
