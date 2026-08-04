<form class="sg-form {{ $bot ? 'sg-group-channel-edit-form' : '' }}" method="POST" action="{{ $action }}">
    @csrf
    @if($bot) @method('PUT') @endif
    <div class="sg-form-grid">
        <label class="sg-field">
            <span>Название бота <b>*</b></span>
            <input type="text" name="bot_name" value="{{ old('bot_name', $bot?->bot_name) }}" required maxlength="255">
            @error('bot_name')<small class="sg-field-error">{{ $message }}</small>@enderror
        </label>
        <label class="sg-field">
            <span>Токен бота {{ $bot ? '' : '*' }}</span>
            <input type="password" name="bot_token" value="" {{ $bot ? '' : 'required' }} maxlength="255" autocomplete="new-password" placeholder="{{ $bot ? 'Оставьте пустым, чтобы не менять' : 'Токен от BotFather' }}">
            @error('bot_token')<small class="sg-field-error">{{ $message }}</small>@enderror
        </label>
        <label class="sg-field">
            <span>ID администратора <b>*</b></span>
            <input type="text" name="admin_id" value="{{ old('admin_id', $bot?->admin_id) }}" required maxlength="64">
            @error('admin_id')<small class="sg-field-error">{{ $message }}</small>@enderror
        </label>
        <label class="sg-field">
            <span>Тип <b>*</b></span>
            <select name="chat_type" required>
                <option value="group" @selected(old('chat_type', $bot?->chat_type) === 'group')>Группа</option>
                <option value="supergroup" @selected(old('chat_type', $bot?->chat_type) === 'supergroup')>Супергруппа</option>
                <option value="channel" @selected(old('chat_type', $bot?->chat_type) === 'channel')>Канал</option>
            </select>
        </label>
        <label class="sg-field">
            <span>Название группы или канала <b>*</b></span>
            <input type="text" name="group_name" value="{{ old('group_name', $bot?->group_name) }}" required maxlength="255">
            @error('group_name')<small class="sg-field-error">{{ $message }}</small>@enderror
        </label>
        <label class="sg-field">
            <span>Ссылка на группу или канал <b>*</b></span>
            <input type="url" name="group_link" value="{{ old('group_link', $bot?->group_link) }}" required maxlength="255" placeholder="https://t.me/example">
            @error('group_link')<small class="sg-field-error">{{ $message }}</small>@enderror
        </label>
    </div>
    <label class="sg-switch-row">
        <span><strong>Активен</strong><small>Разрешить использование этой связки.</small></span>
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $bot?->is_active ?? true))>
    </label>
    <div class="sg-form-actions">
        <button class="sg-button sg-button-primary" type="submit" data-submit-button>Сохранить</button>
    </div>
</form>

@if($bot)
    <hr>
    <form class="sg-form" method="POST" action="{{ route('admin.group-channel.alerts-api-token.update', $bot) }}">
        @csrf @method('PUT')
        <div class="sg-field">
            <label for="alerts-api-token-{{ $bot->id }}">Токен API alerts.in.ua</label>
            <input
                id="alerts-api-token-{{ $bot->id }}"
                type="password"
                name="alerts_api_token"
                value=""
                maxlength="512"
                autocomplete="new-password"
                placeholder="{{ $bot->alerts_api_token ? 'Токен уже добавлен. Введите новый только для замены' : 'Вставьте полученный API-токен' }}"
            >
            <small>Токен хранится зашифрованным и не выводится обратно в форму.</small>
            @error('alerts_api_token')<small class="sg-field-error">{{ $message }}</small>@enderror
        </div>
        @if($bot->alerts_api_token)
            <label class="sg-switch-row">
                <span><strong>Удалить текущий API-токен</strong><small>Модуль перестанет получать данные до добавления нового токена.</small></span>
                <input type="hidden" name="remove_alerts_api_token" value="0">
                <input type="checkbox" name="remove_alerts_api_token" value="1">
            </label>
        @endif
        <div class="sg-form-actions">
            <button class="sg-button sg-button-primary" type="submit" data-submit-button>Сохранить API-токен</button>
        </div>
    </form>
@endif
