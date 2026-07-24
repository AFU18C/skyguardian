<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070d18">
    <title>@yield('title', config('branding.name'))</title>
    <meta name="description" content="@yield('description', 'SkyGuardian — система управления информационными каналами.')">
    <style>
        :root {
            --brand-primary: {{ config('branding.primary_color') }};
            --brand-accent: {{ config('branding.accent_color') }};
        }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @yield('body')
</body>
</html>
