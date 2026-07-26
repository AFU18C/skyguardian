<section class="sg-bot-rights">
    <div class="sg-bot-rights-heading">
        <h3>Права бота</h3>
        <small>Обновляются кнопкой «Проверить подключение»</small>
    </div>
    <div class="sg-bot-rights-grid">
        @foreach([
            'is_administrator' => 'Администратор',
            'send_messages' => 'Отправка сообщений',
            'post_messages' => 'Публикация в канале',
            'edit_messages' => 'Редактирование',
            'delete_messages' => 'Удаление',
            'pin_messages' => 'Закрепление',
            'restrict_members' => 'Ограничение участников',
            'invite_users' => 'Приглашения',
            'manage_chat' => 'Управление чатом',
            'manage_topics' => 'Темы',
        ] as $key => $label)
            @php $allowed = (bool) data_get($bot->permissions, $key, false); @endphp
            <div class="sg-bot-right-item {{ $allowed ? 'is-allowed' : 'is-denied' }}">
                <span>{{ $label }}</span>
                <strong>{{ $allowed ? 'Да' : 'Нет' }}</strong>
            </div>
        @endforeach
    </div>
</section>
