@php
    $mask = static function (?string $value, int $start = 4, int $end = 4): string {
        if (! $value) return 'Не определено';
        $length = mb_strlen($value);
        if ($length <= $start + $end) return mb_substr($value, 0, 2).'••••';
        $suffix = $end > 0 ? mb_substr($value, -$end) : '';
        return mb_substr($value, 0, $start).'••••••••'.$suffix;
    };
    $maskPhone = static function (?string $phone): string {
        if (! $phone) return 'Не определено';
        $digits = preg_replace('/\D+/', '', $phone);
        if (mb_strlen($digits) < 6) return mb_substr($phone, 0, 2).'••••';
        return '+'.mb_substr($digits, 0, 3).' '.mb_substr($digits, 3, 2).' ••• •• '.mb_substr($digits, -2);
    };
    $openAccountId = (int) session('open_account_id', 0);
    $hasQrCode = $accounts->getCollection()->contains(
        fn ($account) => $account->status === 'awaiting_qr'
            && data_get($account->auth_data, 'qr_url')
            && $account->auth_expires_at?->isFuture()
    );
@endphp

<x-layouts.admin title="Настройки Telegram">
    <x-slot:description>Telegram API, технические аккаунты и пошаговая авторизация через функции ТЗ №1.</x-slot:description>
    <x-slot:actions>
        <button class="sg-button sg-button-secondary" type="button" data-modal-open="api-create">Добавить Telegram API</button>
        <button class="sg-button sg-button-primary" type="button" data-modal-open="account-create" @disabled($accountLimitReached)>+ Добавить аккаунт</button>
    </x-slot:actions>

    @if ($accountLimitReached)
        <div class="sg-notice sg-notice-warning">Достигнут лимит: {{ config('skyguardian.limits.technical_accounts', 20) }} технических аккаунтов.</div>
    @endif

    <section class="sg-section-block">
        <div class="sg-section-heading">
            <div><p class="sg-eyebrow">Конфигурации</p><h2>Telegram API</h2><p>API Hash хранится зашифрованно и отображается только частично.</p></div>
        </div>

        @if ($apis->isEmpty())
            <div class="sg-compact-empty">Конфигурации Telegram API ещё не добавлены.</div>
        @else
            <div class="sg-api-grid">
                @foreach ($apis as $api)
                    <article class="sg-api-card">
                        <div class="sg-record-card-top">
                            <div class="sg-record-icon">API</div>
                            <div class="sg-record-title"><h3>{{ $api->name }}</h3><p>{{ $api->is_active ? 'Активна' : 'Отключена' }}</p></div>
                            <span class="sg-status {{ $api->is_active ? 'sg-status-success' : 'sg-status-muted' }}"><span class="sg-status-dot"></span>{{ $api->is_active ? 'Активна' : 'Отключена' }}</span>
                        </div>
                        <dl class="sg-record-data">
                            <div><dt>API ID</dt><dd>{{ $mask((string) $api->api_id, 4, 0) }}</dd></div>
                            <div><dt>API Hash</dt><dd>{{ $mask($api->api_hash) }}</dd></div>
                            <div><dt>Аккаунтов</dt><dd>{{ $api->technical_accounts_count }}</dd></div>
                            <div><dt>Обновлено</dt><dd>{{ $api->updated_at->timezone('Europe/Kyiv')->format('d.m.Y H:i') }}</dd></div>
                        </dl>
                        <button class="sg-button sg-button-secondary sg-button-small" type="button" data-modal-open="api-edit-{{ $api->id }}">Настроить</button>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="sg-section-block">
        <div class="sg-section-heading">
            <div><p class="sg-eyebrow">Подключения</p><h2>Технические аккаунты</h2><p>Создание, код Telegram, пароль 2FA и QR-вход проходят как единый сценарий.</p></div>
        </div>

        @if ($accounts->isEmpty())
            <section class="sg-empty-state sg-empty-state-compact">
                <div class="sg-empty-symbol">➤</div>
                <h2>Технические аккаунты ещё не добавлены.</h2>
                @unless ($accountLimitReached)<button class="sg-button sg-button-primary" type="button" data-modal-open="account-create">Добавить аккаунт</button>@endunless
            </section>
        @else
            <div class="sg-card-grid">
                @foreach ($accounts as $account)
                    <article class="sg-record-card">
                        <div class="sg-record-card-top">
                            <div class="sg-record-icon">➤</div>
                            <div class="sg-record-title"><h2>{{ $account->name }}</h2><p>{{ $account->username ? '@'.$account->username : 'Username не определён' }}</p></div>
                            <x-status-badge :status="$account->is_active ? $account->status : 'disabled'" />
                        </div>
                        <dl class="sg-record-data">
                            <div><dt>Телефон</dt><dd>{{ $maskPhone($account->phone) }}</dd></div>
                            <div><dt>Telegram User ID</dt><dd>{{ $account->telegram_user_id ?: 'Не определено' }}</dd></div>
                            <div><dt>Профиль</dt><dd>{{ trim(($account->first_name ?? '').' '.($account->last_name ?? '')) ?: 'Не определено' }}</dd></div>
                            <div><dt>Telegram API</dt><dd>{{ $account->telegramApi?->name ?? 'Не определено' }}</dd></div>
                            <div><dt>Способ подключения</dt><dd>{{ $account->auth_method === 'qr' ? 'QR-код' : 'Номер телефона' }}</dd></div>
                            <div><dt>Источников</dt><dd>{{ $account->sources->count() }}</dd></div>
                            <div><dt>Последняя проверка</dt><dd>{{ $account->last_manual_check_at?->timezone('Europe/Kyiv')->format('d.m.Y H:i') ?? 'Не выполнялась' }}</dd></div>
                            <div><dt>Обновлено</dt><dd>{{ $account->updated_at->timezone('Europe/Kyiv')->format('d.m.Y H:i') }}</dd></div>
                        </dl>
                        @if ($account->last_error)<div class="sg-inline-error" title="{{ $account->last_error }}">{{ \Illuminate\Support\Str::limit($account->last_error, 120) }}</div>@endif
                        <div class="sg-record-actions">
                            <form method="POST" action="{{ route('admin.telegram.accounts.check', $account) }}">@csrf<button class="sg-button sg-button-secondary sg-button-small" type="submit" data-submit-button>Проверить сейчас</button></form>
                            <button class="sg-button sg-button-primary sg-button-small" type="button" data-modal-open="account-edit-{{ $account->id }}">Открыть</button>
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="sg-pagination">{{ $accounts->links() }}</div>
        @endif
    </section>

    @push('modals')
        <x-modal id="api-create" title="Добавить Telegram API">
            @include('admin.telegram._api_form', ['telegramApi' => null, 'action' => route('admin.telegram.apis.store')])
        </x-modal>

        <x-modal id="account-create" title="Добавить технический аккаунт" size="xl">
            @include('admin.telegram._account_form', ['account' => null, 'apis' => $apis, 'action' => route('admin.telegram.accounts.store')])
        </x-modal>

        @foreach ($apis as $api)
            <x-modal id="api-edit-{{ $api->id }}" title="Настройка Telegram API">
                <div class="sg-secret-summary">
                    <div><span>API ID</span><strong>{{ $mask((string) $api->api_id, 4, 0) }}</strong></div>
                    <div><span>API Hash</span><strong>{{ $mask($api->api_hash) }}</strong></div>
                </div>
                @include('admin.telegram._api_form', ['telegramApi' => $api, 'action' => route('admin.telegram.apis.update', $api)])
                <div class="sg-danger-zone">
                    <div><strong>Удаление Telegram API</strong><p>Удаление возможно только при отсутствии связанных аккаунтов.</p></div>
                    <form method="POST" action="{{ route('admin.telegram.apis.destroy', $api) }}" data-confirm="Удалить эту конфигурацию Telegram API?">@csrf @method('DELETE')<button class="sg-button sg-button-danger" type="submit">Удалить</button></form>
                </div>
            </x-modal>
        @endforeach

        @foreach ($accounts as $account)
            <x-modal id="account-edit-{{ $account->id }}" title="Технический аккаунт" size="xl">
                <div class="sg-secret-summary">
                    <div><span>Телефон</span><strong>{{ $maskPhone($account->phone) }}</strong></div>
                    <div><span>Session</span><strong>{{ $account->session ? 'sess••••••••'.mb_substr(hash('sha256', (string) $account->id), -4) : 'Не создана' }}</strong></div>
                    <div><span>Telegram User ID</span><strong>{{ $account->telegram_user_id ?: 'Не определено' }}</strong></div>
                </div>

                @include('admin.telegram._account_form', ['account' => $account, 'apis' => $apis, 'action' => route('admin.telegram.accounts.update', $account)])
                @include('admin.telegram._connection_panel', ['account' => $account])

                <div class="sg-danger-zone">
                    <div><strong>Удаление технического аккаунта</strong><p>Связанные источники сохранятся, но будут отключены.</p></div>
                    <form method="POST" action="{{ route('admin.telegram.accounts.destroy', $account) }}" data-confirm="Удалить технический аккаунт? Связанные источники будут отключены.">@csrf @method('DELETE')<button class="sg-button sg-button-danger" type="submit">Удалить</button></form>
                </div>
            </x-modal>
        @endforeach
    @endpush

    @if ($errors->any() && old('form_context'))
        @php
            $context = old('form_context');
            $modal = match (true) {
                $context === 'api-create' => 'api-create',
                $context === 'account-create' => 'account-create',
                str_starts_with($context, 'api-') => 'api-edit-'.str_replace('api-', '', $context),
                str_starts_with($context, 'account-') => 'account-edit-'.str_replace('account-', '', $context),
                default => null,
            };
            $errorAccountId = str_starts_with($context, 'account-') && $context !== 'account-create'
                ? (int) str_replace('account-', '', $context)
                : 0;
        @endphp
        @if ($modal)
            <div data-open-modal-on-load="{{ $modal }}" @if($errorAccountId) data-modal-scroll-to="#account-connection-{{ $errorAccountId }}" @endif></div>
        @endif
    @elseif ($openAccountId)
        <div data-open-modal-on-load="account-edit-{{ $openAccountId }}" data-modal-scroll-to="#account-connection-{{ $openAccountId }}"></div>
    @endif

    @push('scripts')
        @if ($hasQrCode)
            <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
        @endif
    @endpush
</x-layouts.admin>
