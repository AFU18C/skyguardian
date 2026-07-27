@php
    $technicalAccounts = \App\Models\TechnicalAccount::query()
        ->orderBy('name')
        ->get();
    $technicalDeleteTasks = $bot->technicalDeleteTasks()
        ->latest()
        ->limit(5)
        ->get();
    $statusLabels = [
        \App\Models\GroupChannelTechnicalDeleteTask::STATUS_PENDING => 'Ожидает',
        \App\Models\GroupChannelTechnicalDeleteTask::STATUS_RUNNING => 'Удаляется',
        \App\Models\GroupChannelTechnicalDeleteTask::STATUS_COMPLETED => 'Завершено',
        \App\Models\GroupChannelTechnicalDeleteTask::STATUS_FAILED => 'Ошибка',
    ];
@endphp

<div class="sg-subpanel">
    <strong>Удаление старой истории через рабочий техаккаунт</strong>
    <p>
        Выберите любой техаккаунт, который уже есть в системе. Предварительная проверка прав не выполняется:
        если аккаунт имеет доступ и право удаления — сообщения будут удалены, иначе задача завершится ошибкой.
    </p>
    <p>Этот режим работает через отдельный процесс и не использует обработчики новостей и воздушных тревог.</p>
</div>

@if($technicalAccounts->isEmpty())
    <div class="sg-inline-error">В системе ещё нет технических аккаунтов.</div>
@else
    <form method="POST" action="{{ route('admin.group-channel.technical-delete.preview', $bot) }}">
        @csrf
        <div class="sg-form-grid">
            <div class="sg-field">
                <label for="technical-delete-account-{{ $bot->id }}">Технический аккаунт</label>
                <select id="technical-delete-account-{{ $bot->id }}" name="technical_account_id" required>
                    <option value="">Выберите техаккаунт</option>
                    @foreach($technicalAccounts as $account)
                        <option value="{{ $account->id }}">
                            {{ $account->name }}
                            @if($account->username) · {{ '@'.$account->username }} @endif
                            · {{ $account->status }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="sg-field">
                <label for="technical-delete-mode-{{ $bot->id }}">Что удалить</label>
                <select id="technical-delete-mode-{{ $bot->id }}" name="mode" required>
                    <option value="period">За выбранный период</option>
                    <option value="last">Последние сообщения</option>
                    <option value="all">Всю доступную историю</option>
                </select>
            </div>
            <div class="sg-field">
                <label for="technical-delete-count-{{ $bot->id }}">Количество последних</label>
                <input id="technical-delete-count-{{ $bot->id }}" name="count" type="number" min="1" max="10000" value="100">
            </div>
            <div class="sg-field">
                <label for="technical-delete-from-{{ $bot->id }}">Дата от</label>
                <input id="technical-delete-from-{{ $bot->id }}" name="date_from" type="datetime-local">
            </div>
            <div class="sg-field">
                <label for="technical-delete-to-{{ $bot->id }}">Дата до</label>
                <input id="technical-delete-to-{{ $bot->id }}" name="date_to" type="datetime-local">
            </div>
        </div>
        <div class="sg-record-actions">
            <button class="sg-button sg-button-secondary" type="submit" data-submit-button>Показать количество</button>
        </div>
    </form>
@endif

@if($technicalDeleteTasks->isNotEmpty())
    <div class="sg-module-history">
        <h4>Последние задачи</h4>
        @foreach($technicalDeleteTasks as $task)
            <div class="sg-switch-row">
                <div>
                    <strong>#{{ $task->id }} · {{ $statusLabels[$task->status] ?? $task->status }}</strong>
                    <small>Техаккаунт: {{ $task->technical_account_name }}</small>
                    <small>
                        Найдено: {{ $task->matched_count }} · удалено: {{ $task->deleted_count }} · ошибок: {{ $task->failed_count }}
                    </small>
                    <small>{{ $task->created_at->timezone('Europe/Kyiv')->format('d.m.Y H:i') }}</small>
                    @if($task->last_error)
                        <div class="sg-inline-error">{{ $task->last_error }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
