@extends('layouts.admin')

@section('title', 'Источники воздушной тревоги — SkyGuardian')
@section('section', 'Воздушная тревога')
@section('heading', 'Источники')

@section('content')
    @if (session('success'))
        <div class="alert-message alert-success">{{ session('success') }}</div>
    @endif

    <div id="source-test-message" class="alert-message" role="status" hidden></div>

    <div class="page-actions">
        <a class="button button-primary" href="{{ route('alerts.sources.create') }}">Добавить источник</a>
    </div>

    @if ($sources->isEmpty())
        <section class="panel">
            <p class="empty-text">Источники ещё не добавлены.</p>
        </section>
    @else
        <div class="source-grid">
            @foreach ($sources as $source)
                @php
                    $manualStatus = $source->manual_check_status;
                    $statusIcon = match ($manualStatus) {
                        'available' => '🟢',
                        'unavailable' => '🔴',
                        default => '⚪',
                    };
                    $statusText = match ($manualStatus) {
                        'available' => 'Доступен',
                        'unavailable' => 'Недоступен',
                        default => 'Не проверялся',
                    };
                    $checkedAt = $source->manual_checked_at
                        ? \Illuminate\Support\Carbon::parse($source->manual_checked_at)->format('d.m.Y H:i:s')
                        : '—';
                @endphp

                <section class="panel source-card" data-source-card>
                    <div class="source-card-header">
                        <div>
                            <h2>{{ $source->name }}</h2>
                            <div class="source-meta">
                                <span>{{ strtoupper($source->type) }}</span>
                                <span>Каждые {{ $source->check_interval }} сек.</span>
                            </div>
                        </div>

                        <div class="action-menu" data-action-menu>
                            <button
                                type="button"
                                class="action-menu-toggle"
                                aria-label="Открыть меню действий"
                                aria-haspopup="true"
                                aria-expanded="false"
                                data-action-menu-toggle
                            >
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <circle cx="12" cy="5" r="1.75"></circle>
                                    <circle cx="12" cy="12" r="1.75"></circle>
                                    <circle cx="12" cy="19" r="1.75"></circle>
                                </svg>
                            </button>

                            <div class="action-menu-dropdown" role="menu" hidden data-action-menu-dropdown>
                                <a class="action-menu-item" role="menuitem" href="{{ $source->address }}" target="_blank" rel="noopener">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    <span>Открыть</span>
                                </a>

                                @if ($source->type === 'telegram')
                                    <button
                                        class="action-menu-item"
                                        type="button"
                                        role="menuitem"
                                        data-source-test
                                        data-test-url="{{ route('alerts.sources.test', $source) }}"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 4v5h5"></path><path d="M4 13a8.1 8.1 0 0 0 15.5 2M20 20v-5h-5"></path></svg>
                                        <span data-source-test-label>Проверить сейчас</span>
                                    </button>
                                @endif

                                <a class="action-menu-item" role="menuitem" href="{{ route('alerts.sources.edit', $source) }}">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m16.862 3.487 3.651 3.651M18.688 1.661a2.582 2.582 0 1 1 3.651 3.651L8.25 19.401l-4.875 1.224 1.224-4.875L18.688 1.661Z"></path></svg>
                                    <span>Редактировать</span>
                                </a>

                                <div class="action-menu-divider" aria-hidden="true"></div>

                                <form method="POST" action="{{ route('alerts.sources.destroy', $source) }}" onsubmit="return confirm('Удалить источник?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="action-menu-item action-menu-danger" role="menuitem">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4.5A1.5 1.5 0 0 1 9.5 3h5A1.5 1.5 0 0 1 16 4.5V6m2 0-.75 14.25A1.5 1.5 0 0 1 15.75 21h-7.5a1.5 1.5 0 0 1-1.5-.75L6 6m4 4v7m4-7v7"></path></svg>
                                        <span>Удалить</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <dl class="source-details">
                        <div><dt>Источник</dt><dd>{{ $source->address }}</dd></div>
                        <div><dt>Публикация</dt><dd>{{ $source->publication_chat }}</dd></div>
                        <div>
                            <dt>Ручной статус</dt>
                            <dd><span data-manual-status-icon>{{ $statusIcon }}</span> <span data-manual-status-text>{{ $statusText }}</span></dd>
                        </div>
                        <div><dt>Последняя ручная проверка</dt><dd data-manual-checked-at>{{ $checkedAt }}</dd></div>
                    </dl>
                </section>
            @endforeach
        </div>
    @endif
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const menus = Array.from(document.querySelectorAll('[data-action-menu]'));

        const closeMenu = (menu) => {
            const toggle = menu.querySelector('[data-action-menu-toggle]');
            const dropdown = menu.querySelector('[data-action-menu-dropdown]');
            dropdown.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            menu.classList.remove('is-open');
        };

        const closeAll = (except = null) => {
            menus.forEach((menu) => {
                if (menu !== except) closeMenu(menu);
            });
        };

        menus.forEach((menu) => {
            const toggle = menu.querySelector('[data-action-menu-toggle]');
            const dropdown = menu.querySelector('[data-action-menu-dropdown]');

            toggle.addEventListener('click', (event) => {
                event.stopPropagation();
                const willOpen = dropdown.hidden;
                closeAll(menu);
                dropdown.hidden = !willOpen;
                toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                menu.classList.toggle('is-open', willOpen);
            });

            dropdown.addEventListener('click', (event) => event.stopPropagation());
        });

        const message = document.getElementById('source-test-message');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

        const showTestMessage = (text, ok) => {
            message.textContent = text;
            message.classList.remove('alert-success', 'alert-error');
            message.classList.add(ok ? 'alert-success' : 'alert-error');
            message.hidden = false;
            message.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        };

        const updateCardStatus = (button, data) => {
            const card = button.closest('[data-source-card]');
            const icon = card?.querySelector('[data-manual-status-icon]');
            const text = card?.querySelector('[data-manual-status-text]');
            const checkedAt = card?.querySelector('[data-manual-checked-at]');

            if (!card || !icon || !text || !checkedAt || !data.manual_status) {
                return;
            }

            const available = data.manual_status === 'available';
            icon.textContent = available ? '🟢' : '🔴';
            text.textContent = available ? 'Доступен' : 'Недоступен';
            checkedAt.textContent = data.manual_checked_at || '—';
        };

        document.querySelectorAll('[data-source-test]').forEach((button) => {
            button.addEventListener('click', async () => {
                const label = button.querySelector('[data-source-test-label]');
                const originalLabel = label.textContent;

                closeAll();
                button.disabled = true;
                label.textContent = 'Проверяем…';

                try {
                    const response = await fetch(button.dataset.testUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    const data = await response.json().catch(() => ({}));
                    updateCardStatus(button, data);
                    showTestMessage(data.message || 'Не удалось проверить источник.', response.ok && data.ok === true);
                } catch (error) {
                    showTestMessage('Ошибка соединения с сервером SkyGuardian.', false);
                } finally {
                    button.disabled = false;
                    label.textContent = originalLabel;
                }
            });
        });

        document.addEventListener('click', () => closeAll());
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeAll();
        });
    });
</script>
@endpush
