@if($bot->moduleEnabled('welcome'))
    <hr>
    <h3>Фото приветствия</h3>
    <form method="POST" action="{{ route('admin.group-channel.welcome-photo.update', $bot) }}" enctype="multipart/form-data">
        @csrf
        <div class="sg-form-grid">
            <div class="sg-field">
                <label for="welcome-photo-{{ $bot->id }}">Фото</label>
                <input id="welcome-photo-{{ $bot->id }}" name="photo" type="file" accept="image/*" required>
                <small>{{ $bot->moduleSetting('welcome', 'photo') ? 'Фото уже загружено. Новое заменит текущее.' : 'Фото ещё не загружено.' }}</small>
            </div>
            <div class="sg-record-actions">
                <button class="sg-button sg-button-secondary" type="submit">Сохранить фото</button>
            </div>
        </div>
    </form>
    @if($bot->moduleSetting('welcome', 'photo'))
        <form method="POST" action="{{ route('admin.group-channel.welcome-photo.destroy', $bot) }}" data-confirm="Удалить фото приветствия?">
            @csrf
            @method('DELETE')
            <button class="sg-button sg-button-danger" type="submit">Удалить фото</button>
        </form>
    @endif
@endif

@if($bot->moduleEnabled('join_requests'))
    <hr>
    <h3>Заявки на вступление</h3>
    @php
        $pendingJoinRequests = $bot->joinRequests()
            ->where('status', \App\Models\GroupChannelJoinRequest::STATUS_PENDING)
            ->latest('requested_at')
            ->limit(50)
            ->get();
    @endphp
    @forelse($pendingJoinRequests as $joinRequest)
        <div class="sg-switch-row">
            <div>
                <strong>{{ trim($joinRequest->first_name.' '.$joinRequest->last_name) ?: 'Пользователь '.$joinRequest->telegram_user_id }}</strong>
                <small>
                    ID: {{ $joinRequest->telegram_user_id }}
                    @if($joinRequest->username) · @{{ $joinRequest->username }} @endif
                    @if($joinRequest->requested_at) · {{ $joinRequest->requested_at->timezone('Europe/Kyiv')->format('d.m.Y H:i') }} @endif
                </small>
                @if($joinRequest->last_error)<div class="sg-inline-error">{{ $joinRequest->last_error }}</div>@endif
            </div>
            <div class="sg-record-actions">
                <form method="POST" action="{{ route('admin.group-channel.join-requests.approve', [$bot, $joinRequest]) }}">
                    @csrf
                    <button class="sg-button sg-button-primary" type="submit">Одобрить</button>
                </form>
                <form method="POST" action="{{ route('admin.group-channel.join-requests.decline', [$bot, $joinRequest]) }}">
                    @csrf
                    <button class="sg-button sg-button-danger" type="submit">Отказать</button>
                </form>
            </div>
        </div>
    @empty
        <p>Ожидающих заявок нет.</p>
    @endforelse
@endif
