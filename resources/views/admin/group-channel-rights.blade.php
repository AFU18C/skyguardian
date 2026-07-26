<h3>Права бота</h3>
@foreach([
    'is_administrator' => 'Бот является администратором',
    'send_messages' => 'Отправка сообщений',
    'post_messages' => 'Публикация в канале',
    'edit_messages' => 'Редактирование сообщений',
    'delete_messages' => 'Удаление сообщений',
    'pin_messages' => 'Закрепление сообщений',
    'restrict_members' => 'Ограничение пользователей',
    'invite_users' => 'Управление приглашениями',
    'manage_chat' => 'Управление чатом',
    'manage_topics' => 'Управление темами',
] as $key => $label)
    @php $allowed = (bool) data_get($bot->permissions, $key, false); @endphp
    <div class="sg-switch-row">
        <strong>{{ $label }}</strong>
        <span class="sg-status {{ $allowed ? 'sg-status-success' : 'sg-status-error' }}">
            <span class="sg-status-dot"></span>{{ $allowed ? 'Разрешено' : 'Нет права' }}
        </span>
    </div>
@endforeach
