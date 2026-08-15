<x-layouts.admin title="Безопасность">
    <div class="sg-settings-grid">
        <section class="sg-section-block">
            <div class="sg-section-heading"><div><p class="sg-eyebrow">Учётная запись</p><h2>Двухфакторная аутентификация</h2><p>К паролю добавляется одноразовый код из приложения.</p></div></div>

            @if(session('mfa_recovery_codes'))
                <div class="sg-notice sg-notice-warning">
                    <strong>Сохраните резервные коды сейчас — повторно они не показываются.</strong>
                    <ul>@foreach(session('mfa_recovery_codes') as $code)<li><code>{{ $code }}</code></li>@endforeach</ul>
                </div>
            @endif

            @if(auth()->user()->mfaEnabled())
                <div class="sg-notice sg-notice-success">MFA включена с {{ auth()->user()->mfa_enabled_at->format('d.m.Y H:i') }}.</div>
                <form class="sg-form" method="POST" action="{{ route('admin.security.mfa.disable') }}" data-confirm="Отключить двухфакторную защиту?">
                    @csrf @method('DELETE')
                    <div class="sg-form-grid">
                        <label class="sg-field"><span>Текущий пароль</span><input type="password" name="password" autocomplete="current-password" required></label>
                        <label class="sg-field"><span>Код приложения</span><input name="code" inputmode="numeric" autocomplete="one-time-code" required></label>
                    </div>
                    <button class="sg-button sg-button-danger" type="submit">Отключить MFA</button>
                </form>
            @elseif($setupSecret)
                <div class="sg-form-grid">
                    <div><div data-qr-url="{{ $setupUri }}"></div><small>Секрет для ручного ввода: <code>{{ $setupSecret }}</code></small></div>
                    <form class="sg-form" method="POST" action="{{ route('admin.security.mfa.enable') }}">
                        @csrf
                        <label class="sg-field"><span>Код из приложения</span><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required autofocus></label>
                        @error('code')<small class="sg-field-error">{{ $message }}</small>@enderror
                        <button class="sg-button sg-button-primary" type="submit">Подтвердить и включить</button>
                    </form>
                </div>
            @else
                <form class="sg-form" method="POST" action="{{ route('admin.security.mfa.begin') }}">
                    @csrf
                    <label class="sg-field"><span>Подтвердите текущий пароль</span><input type="password" name="password" autocomplete="current-password" required></label>
                    @error('password')<small class="sg-field-error">{{ $message }}</small>@enderror
                    <button class="sg-button sg-button-primary" type="submit">Настроить MFA</button>
                </form>
            @endif
        </section>

        @if(auth()->user()->role === \App\Models\User::ROLE_ADMINISTRATOR)
            <section class="sg-section-block">
                <div class="sg-section-heading"><div><p class="sg-eyebrow">Доступ</p><h2>Роли пользователей</h2><p>Наблюдатель может только просматривать; оператор работает с модулями; администратор управляет безопасностью.</p></div></div>
                @foreach($users as $user)
                    <form class="sg-switch-row" method="POST" action="{{ route('admin.security.users.role', $user) }}">
                        @csrf @method('PUT')
                        <span><strong>{{ $user->name }}</strong><small>{{ $user->email }}</small></span>
                        <select name="role">
                            <option value="administrator" @selected($user->role === 'administrator')>Администратор</option>
                            <option value="operator" @selected($user->role === 'operator')>Оператор</option>
                            <option value="viewer" @selected($user->role === 'viewer')>Наблюдатель</option>
                        </select>
                        <button class="sg-button sg-button-small sg-button-secondary" type="submit">Сохранить</button>
                    </form>
                @endforeach
            </section>

            <section class="sg-section-block">
                <div class="sg-section-heading"><div><p class="sg-eyebrow">Контроль</p><h2>Журнал действий</h2><p>Последние изменения, входы и административные операции.</p></div></div>
                <div class="sg-table-wrap"><table class="sg-table"><thead><tr><th>Время</th><th>Пользователь</th><th>Действие</th><th>Статус</th><th>IP</th></tr></thead><tbody>
                @forelse($auditLogs as $log)
                    <tr><td>{{ $log->created_at->format('d.m.Y H:i:s') }}</td><td>{{ $log->user?->email ?: 'Не определён' }}</td><td><code>{{ $log->event }}</code></td><td>{{ $log->response_status }}</td><td>{{ $log->ip_address }}</td></tr>
                @empty<tr><td colspan="5">Журнал пока пуст.</td></tr>@endforelse
                </tbody></table></div>
                <div class="sg-pagination">{{ $auditLogs->withQueryString()->links() }}</div>
            </section>
        @endif
    </div>
</x-layouts.admin>
