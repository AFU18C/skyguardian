@props(['code', 'title', 'message', 'login' => false])

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $code }} — {{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="sg-error-page">
    <section class="sg-error-card">
        <div class="sg-public-emblem" style="margin: 0 auto 24px">SG</div>
        <div class="sg-error-code">{{ $code }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        @if ($login)
            <a class="sg-button sg-button-primary" href="{{ route('admin.login') }}">Войти повторно</a>
        @elseif (auth()->check())
            <a class="sg-button sg-button-primary" href="{{ route('admin.dashboard') }}">Вернуться в админку</a>
        @else
            <a class="sg-button sg-button-primary" href="{{ route('home') }}">На главную</a>
        @endif
    </section>
</main>
</body>
</html>
