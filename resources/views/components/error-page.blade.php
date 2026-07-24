@props(['code', 'title', 'description'])

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070d18">
    <title>{{ $code }} — {{ $title }} — {{ config('branding.name') }}</title>
    <style>
        :root {
            --brand-primary: {{ config('branding.primary_color') }};
            --brand-accent: {{ config('branding.accent_color') }};
        }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="error-shell">
    <section class="error-card">
        <x-brand class="mb-12"/>
        <p class="error-code">{{ $code }}</p>
        <h1 class="error-title">{{ $title }}</h1>
        <p class="error-copy">{{ $description }}</p>
        <div class="hero-actions justify-center">
            <x-ui.button :href="route('home')" variant="primary">
                <x-icon name="home" :size="15"/>
                На главную
            </x-ui.button>
            @auth
                <x-ui.button :href="route('dashboard')">В панель управления</x-ui.button>
            @endauth
        </div>
    </section>
</main>
</body>
</html>
