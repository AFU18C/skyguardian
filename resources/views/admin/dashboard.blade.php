<x-layouts.admin title="Главная">
    <section
        class="sg-dashboard-overview"
        data-vps-metrics
        data-metrics-url="{{ route('admin.system.metrics') }}"
        data-backup-status-url="{{ route('admin.system.backup.show') }}"
        data-backup-create-url="{{ route('admin.system.backup.store') }}"
    >
        <header class="sg-dashboard-overview-head">
            <div>
                <span class="sg-dashboard-eyebrow">Состояние сервера</span>
                <h2>Нагрузка VPS</h2>
                <p>Показатели обновляются автоматически каждые 15 секунд.</p>
            </div>
            <div class="sg-dashboard-live" data-vps-summary>
                <span></span>
                <strong>Получаем данные</strong>
            </div>
        </header>

        <div class="sg-dashboard-metrics-grid">
            <article class="sg-dashboard-metric" data-vps-metric="cpu">
                <div class="sg-dashboard-metric-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <rect x="6" y="6" width="12" height="12" rx="2"></rect>
                        <rect x="9" y="9" width="6" height="6" rx="1"></rect>
                        <path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"></path>
                    </svg>
                </div>
                <div class="sg-dashboard-metric-copy">
                    <span>Процессор</span>
                    <strong data-vps-metric-value>--%</strong>
                    <small data-vps-metric-status>Ожидание данных</small>
                </div>
                <div class="sg-dashboard-meter" aria-hidden="true">
                    <span data-vps-meter-fill></span>
                </div>
            </article>

            <article class="sg-dashboard-metric" data-vps-metric="memory">
                <div class="sg-dashboard-metric-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="7" width="18" height="10" rx="2"></rect>
                        <path d="M7 10v4M11 10v4M15 10v4M19 10v4M6 17v3M18 17v3"></path>
                    </svg>
                </div>
                <div class="sg-dashboard-metric-copy">
                    <span>Оперативная память</span>
                    <strong data-vps-metric-value>--%</strong>
                    <small data-vps-metric-status>Ожидание данных</small>
                </div>
                <div class="sg-dashboard-meter" aria-hidden="true">
                    <span data-vps-meter-fill></span>
                </div>
            </article>

            <article class="sg-dashboard-metric" data-vps-metric="disk">
                <div class="sg-dashboard-metric-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <ellipse cx="12" cy="5" rx="8" ry="3"></ellipse>
                        <path d="M4 5v7c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7"></path>
                    </svg>
                </div>
                <div class="sg-dashboard-metric-copy">
                    <span>Диск</span>
                    <strong data-vps-metric-value>--%</strong>
                    <small data-vps-metric-status>Ожидание данных</small>
                </div>
                <div class="sg-dashboard-meter" aria-hidden="true">
                    <span data-vps-meter-fill></span>
                </div>
            </article>

            <article class="sg-dashboard-metric sg-dashboard-backup" data-site-backup>
                <div class="sg-dashboard-metric-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M5 8V4h14v4M5 16v4h14v-4"></path>
                        <rect x="3" y="8" width="18" height="8" rx="2"></rect>
                        <path d="M8 12h8M17.5 11.5h.01"></path>
                    </svg>
                </div>
                <div class="sg-dashboard-metric-copy">
                    <span>Резервная копия сайта</span>
                    <strong data-backup-state>Проверяем</strong>
                    <small data-backup-description>Получаем данные о последней копии</small>
                </div>
                <div class="sg-dashboard-backup-actions">
                    <div class="sg-dashboard-backup-last">
                        <span>Последняя копия</span>
                        <strong data-backup-last>—</strong>
                    </div>
                    <button class="sg-button sg-button-primary" type="button" data-backup-create disabled>
                        <span>Создать бэкап</span>
                    </button>
                </div>
            </article>
        </div>

        <footer class="sg-dashboard-overview-foot">
            <span>Последнее обновление</span>
            <strong data-vps-updated-at>—</strong>
        </footer>
    </section>

    <section class="sg-source-health-section">
        <header class="sg-source-health-head">
            <div>
                <span class="sg-dashboard-eyebrow">Рабочие процессы</span>
                <h2>Новости и тревоги</h2>
                <p>Статус рассчитывается по подключению аккаунта, ошибкам и времени последней успешной обработки.</p>
            </div>
        </header>

        <div class="sg-source-health-grid">
            @foreach($sourceHealth as $item)
                <article class="sg-source-health-card is-{{ $item['state'] }}">
                    <div class="sg-source-health-status">
                        <span></span>
                        <strong>{{ $item['status'] }}</strong>
                    </div>
                    <h3>{{ $item['label'] }}</h3>
                    <p>{{ $item['description'] }}</p>
                    <dl>
                        <dt>Последняя успешная обработка</dt>
                        <dd>{{ $item['last_success_at']?->diffForHumans() ?? 'ещё не выполнялась' }}</dd>
                    </dl>
                </article>
            @endforeach
        </div>
    </section>
</x-layouts.admin>
