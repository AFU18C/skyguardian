@extends('layouts.admin')

@section('title', 'Настройка')
@section('section', 'Новости')
@section('page-title', 'Настройка')

@section('content')
<div x-data="{ formOpen: false }">
    <div class="content-heading">
        <div>
            <h1>Настройка</h1>
            <p>Telegram API и технические аккаунты новостей.</p>
        </div>
        <x-ui.button
            variant="primary"
            x-on:click="formOpen = !formOpen"
            x-bind:aria-expanded="formOpen"
            aria-controls="news-api-form"
        >
            <x-icon name="plus" :size="15"/>
            <span x-text="formOpen ? 'Закрыть' : 'Добавить'">Добавить</span>
        </x-ui.button>
    </div>

    <section
        id="news-api-form"
        x-cloak
        x-show="formOpen"
        x-transition
        class="panel settings-form-card"
    >
        <header class="panel-header">
            <div>
                <div class="panel-title">Добавить Telegram API и технический аккаунт</div>
                <div class="panel-copy">Заполните данные подключения.</div>
            </div>
        </header>

        <form class="panel-body settings-form" @submit.prevent>
            <div class="settings-form-grid">
                <label class="field settings-form-wide">
                    <span class="field-label">Название API</span>
                    <input class="input" type="text" placeholder="Основной аккаунт" autocomplete="off">
                </label>

                <label class="field">
                    <span class="field-label">API ID</span>
                    <input class="input" type="text" inputmode="numeric" autocomplete="off">
                </label>

                <label class="field">
                    <span class="field-label">API Hash</span>
                    <input class="input" type="text" autocomplete="off">
                </label>

                <label class="field">
                    <span class="field-label">Вход</span>
                    <select class="input">
                        <option>Телефон</option>
                    </select>
                </label>

                <label class="field">
                    <span class="field-label">Номер телефона</span>
                    <input class="input" type="tel" placeholder="+380..." autocomplete="off">
                </label>
            </div>

            <x-ui.button
                type="button"
                variant="primary"
                class="settings-form-submit"
                disabled
                title="Это шаблон формы без сохранения данных"
            >
                Добавить
            </x-ui.button>
        </form>
    </section>

    <section class="panel">
        <header class="panel-header">
            <div>
                <div class="panel-title">Telegram API и технические аккаунты</div>
                <div class="panel-copy">Настройки раздела «Новости».</div>
            </div>
        </header>
        <x-ui.empty-state
            title="API и технические аккаунты ещё не добавлены"
            description="Нажмите «Добавить», чтобы открыть форму."
            icon="settings"
        />
    </section>
</div>
@endsection
