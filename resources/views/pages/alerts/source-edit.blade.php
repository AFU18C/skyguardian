@extends('layouts.admin')

@section('title', 'Редактировать источник — SkyGuardian')
@section('section', 'Воздушная тревога')
@section('heading', 'Редактировать источник')

@section('content')
    @include('pages.alerts.source-form', [
        'action' => route('alerts.sources.update', $source),
        'method' => 'PUT',
        'source' => $source,
        'submitLabel' => 'Сохранить изменения',
    ])
@endsection
