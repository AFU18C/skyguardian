<x-layouts.admin title="Группа-Канал">
    <x-slot:actions>
        <button class="sg-button sg-button-primary" type="button" data-modal-open="group-channel-create">+ Добавить Группу-Канал</button>
    </x-slot:actions>

    @if ($bots->isEmpty())
        <section class="sg-empty-state sg-empty-state-compact">
            <div class="sg-empty-symbol">➤</div>
            <h2>Боты групп и каналов ещё не добавлены</h2>
            <button class="sg-button sg-button-primary" type="button" data-modal-open="group-channel-create">Добавить</button>
        </section>
    @else
        <div class="sg-group-channel-grid">
            @foreach ($bots as $bot)
                <article class="sg-group-channel-card">
                    <div class="sg-group-channel-mark" aria-hidden="true">▲</div>
                    <div class="sg-group-channel-title">
                        <h2>{{ $bot->group_name }}</h2>
                        <a href="{{ $bot->group_link }}" target="_blank" rel="noopener">
                            {{ $bot->bot_username ? '@'.$bot->bot_username : $bot->bot_name }}
                        </a>
                    </div>
                    <div class="sg-group-channel-actions">
                        <button class="sg-group-channel-action" type="button" title="Редактировать" aria-label="Редактировать" data-modal-open="group-channel-edit-{{ $bot->id }}">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4Z"></path><path d="m13.5 6.5 4 4"></path></svg>
                        </button>
                        <button class="sg-group-channel-action" type="button" title="Управление" aria-label="Управление" data-modal-open="group-channel-manage-{{ $bot->id }}">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1v.1h-4v-.1a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1-.4h-.1v-4H3A1.7 1.7 0 0 0 4.6 8.5a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1v-.1h4V3a1.7 1.7 0 0 0 1.1 1.6 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.38.32.6.79.6 1.3v3.4c0 .5-.22.98-.6 1.3Z"></path></svg>
                        </button>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="sg-pagination">{{ $bots->links() }}</div>
    @endif

    @push('modals')
        <x-modal id="group-channel-create" title="Добавить Группу-Канал" size="lg">
            @include('admin.group-channel-form', ['bot' => null, 'action' => route('admin.group-channel.store')])
        </x-modal>

        @foreach($bots as $bot)
            <x-modal id="group-channel-edit-{{ $bot->id }}" title="Редактировать Группа-Канал" size="lg">
                @include('admin.group-channel-form', ['bot' => $bot, 'action' => route('admin.group-channel.update', $bot)])
            </x-modal>
            <x-modal id="group-channel-manage-{{ $bot->id }}" title="Управление: {{ $bot->group_name }}" size="lg">
                @include('admin.group-channel-rights', ['bot' => $bot])
                @include('admin.group-channel-management', ['bot' => $bot])
            </x-modal>
        @endforeach

        @if(session('group_channel_publication_preview'))
            @php $preview = session('group_channel_publication_preview'); @endphp
            <x-modal id="group-channel-publication-preview" title="Предпросмотр публикации">
                <dl class="sg-record-data">
                    <div><dt>Тип</dt><dd>{{ $preview['type'] }}</dd></div>
                    <div><dt>Текст</dt><dd>{!! nl2br(e($preview['text'])) !!}</dd></div>
                    <div><dt>Файлы</dt><dd>{{ implode(', ', $preview['files'] ?? []) ?: 'Нет' }}</dd></div>
                    <div><dt>Реакции</dt><dd>{{ implode(', ', $preview['reactions'] ?? []) ?: 'Нет' }}</dd></div>
                </dl>
                @if($preview['poll'] ?? null)
                    <h3>{{ data_get($preview, 'poll.question') }}</h3>
                    <ol>@foreach(data_get($preview, 'poll.options', []) as $option)<li>{{ $option }}</li>@endforeach</ol>
                @endif
                @if($preview['buttons'] ?? null)
                    <div class="sg-record-actions">@foreach($preview['buttons'] as $row)@foreach($row as $button)<span class="sg-button sg-button-secondary">{{ $button['text'] }}</span>@endforeach @endforeach</div>
                @endif
            </x-modal>
            <div data-open-modal-on-load="group-channel-publication-preview"></div>
        @endif

        @if(session('group_channel_bulk_delete_preview'))
            @php $bulkPreview = session('group_channel_bulk_delete_preview'); @endphp
            <x-modal id="group-channel-bulk-delete-preview" title="Подтверждение массового удаления">
                <p>Найдено сообщений: <strong>{{ $bulkPreview['count'] }}</strong>.</p>
                <p>После подтверждения бот попробует удалить каждое найденное сообщение из Telegram.</p>
                <form method="POST" action="{{ route('admin.group-channel.bulk-delete.execute', $bulkPreview['bot_id']) }}" data-confirm="Подтвердить массовое удаление {{ $bulkPreview['count'] }} сообщений?">
                    @csrf
                    <input type="hidden" name="token" value="{{ $bulkPreview['token'] }}">
                    <button class="sg-button sg-button-danger" type="submit">Подтвердить удаление</button>
                </form>
            </x-modal>
            <div data-open-modal-on-load="group-channel-bulk-delete-preview"></div>
        @endif
    @endpush

    @if(session('open_group_channel_manage'))
        <div
            data-open-modal-on-load="group-channel-manage-{{ (int) session('open_group_channel_manage') }}"
            @if(session('open_group_channel_scroll')) data-modal-scroll-to="{{ session('open_group_channel_scroll') }}" @endif
        ></div>
    @endif

    @if($errors->any() && old('bot_name'))<div data-open-modal-on-load="group-channel-create"></div>@endif
</x-layouts.admin>
