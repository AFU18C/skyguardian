@php
    $trackedMessageCount = $bot->messages()->whereNull('deleted_at_telegram')->count();
@endphp

<div class="sg-subpanel">
    <strong>Доступно для удаления: {{ $trackedMessageCount }}</strong>
    <p>
        Telegram Bot API не передаёт старую историю. SkyGuardian может удалить только сообщения и публикации,
        полученные после подключения webhook. После подключения опубликуйте новое сообщение в канале или группе.
    </p>
</div>
