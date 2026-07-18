@extends('layouts.admin')

@section('title', 'Добавить источник — Новости')
@section('section', 'Новости')
@section('heading', 'Добавить источник')

@section('content')
    <section class="panel">
        <form class="source-form">
            <label>
                <span>Название источника</span>
                <input type="text" name="name">
            </label>

            <label>
                <span>Тип источника</span>
                <select name="type" id="source-type">
                    <option value="api">API</option>
                    <option value="telegram">Telegram-канал</option>
                    <option value="website">Сайт</option>
                </select>
            </label>

            <label>
                <span id="source-address-label">URL API</span>
                <input type="text" name="address">
            </label>

            <label>
                <span>Чат или канал для публикации</span>
                <input type="text" name="publication_chat">
            </label>

            <label>
                <span>Интервал проверки новых сообщений</span>
                <input type="number" name="check_interval" min="1">
            </label>

            <div class="form-actions">
                <button class="button button-primary" type="button">Сохранить</button>
                <a class="button button-secondary" href="{{ route('news.sources') }}">Отмена</a>
            </div>
        </form>
    </section>

    <script>
        const typeField = document.getElementById('source-type');
        const addressLabel = document.getElementById('source-address-label');
        const labels = {
            api: 'URL API',
            telegram: 'Telegram-канал',
            website: 'URL сайта'
        };

        typeField.addEventListener('change', () => {
            addressLabel.textContent = labels[typeField.value];
        });
    </script>
@endsection
