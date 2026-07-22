(() => {
  'use strict';

  const host = document.querySelector('[data-worker-monitor]');
  if (!host) return;

  const labels = {
    news: 'Новости',
    alerts: 'Воздушная тревога',
    running: 'Работает',
    idle: 'Ожидает',
    error: 'Ошибка',
    stale: 'Нет отклика',
    not_started: 'Не запускался'
  };

  const escapeHtml = value => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const formatDuration = milliseconds => {
    const value = Number(milliseconds || 0);
    if (value < 1000) return `${value} мс`;
    if (value < 60000) return `${(value / 1000).toFixed(1)} с`;
    return `${Math.floor(value / 60000)} мин ${Math.floor((value % 60000) / 1000)} с`;
  };

  const formatAge = seconds => {
    const value = Math.max(0, Number(seconds || 0));
    if (value < 60) return `${value} сек назад`;
    if (value < 3600) return `${Math.floor(value / 60)} мин назад`;
    if (value < 86400) return `${Math.floor(value / 3600)} ч назад`;
    return `${Math.floor(value / 86400)} дн назад`;
  };

  const errorList = errors => {
    if (!Array.isArray(errors) || errors.length === 0) {
      return '<div class="worker-monitor-empty">Ошибок нет</div>';
    }

    return `<div class="worker-monitor-errors">${errors.map(error => `
      <article>
        <strong>${escapeHtml(error.channel_name || error.channel_id || 'Worker')}</strong>
        <time>${escapeHtml(error.at || '')}</time>
        <p>${escapeHtml(error.message || 'Неизвестная ошибка')}</p>
      </article>
    `).join('')}</div>`;
  };

  const renderCard = worker => {
    const status = worker.status || 'not_started';
    const metrics = worker.metrics || {};
    const channels = worker.channels || {};
    const lastRun = worker.age_seconds == null ? 'Нет данных' : formatAge(worker.age_seconds);

    return `
      <section class="worker-monitor-card status-${escapeHtml(status)}" data-worker-card="${escapeHtml(worker.scope)}">
        <header>
          <div>
            <span class="worker-monitor-dot" aria-hidden="true"></span>
            <div><strong>${escapeHtml(labels[worker.scope] || worker.scope)}</strong><small>Telegram worker</small></div>
          </div>
          <span class="worker-monitor-status">${escapeHtml(labels[status] || status)}</span>
        </header>
        <div class="worker-monitor-grid">
          <div><span>Последний запуск</span><strong>${escapeHtml(lastRun)}</strong></div>
          <div><span>Длительность</span><strong>${escapeHtml(formatDuration(metrics.duration_ms))}</strong></div>
          <div><span>Обработано</span><strong>${Number(metrics.processed_count || 0)}</strong></div>
          <div><span>Опубликовано</span><strong>${Number(metrics.published_count || 0)}</strong></div>
          <div><span>Повторные попытки</span><strong>${Number(metrics.retry_count || 0)}</strong></div>
          <div><span>Каналы с ошибками</span><strong>${Number(channels.error || 0)} / ${Number(channels.total || 0)}</strong></div>
        </div>
        <button class="worker-monitor-toggle" type="button" data-worker-errors-toggle>
          Последние ошибки <span>${Array.isArray(worker.errors) ? worker.errors.length : 0}</span>
        </button>
        <div class="worker-monitor-details" hidden>${errorList(worker.errors)}</div>
      </section>
    `;
  };

  const render = payload => {
    const workers = Array.isArray(payload?.workers)
      ? payload.workers
      : Object.values(payload?.workers || {});

    if (workers.length === 0) {
      host.innerHTML = '<div class="worker-monitor-message">Данные worker’ов пока недоступны.</div>';
      return;
    }

    host.innerHTML = workers.map(renderCard).join('');
    host.querySelectorAll('[data-worker-errors-toggle]').forEach(button => {
      button.addEventListener('click', () => {
        const details = button.nextElementSibling;
        const open = details.hidden;
        details.hidden = !open;
        button.classList.toggle('open', open);
      });
    });
  };

  const load = async ({ quiet = false } = {}) => {
    if (!quiet) host.classList.add('loading');
    try {
      const response = await fetch('/worker-status.php', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      const payload = await response.json();
      if (!response.ok || payload.ok === false) throw new Error(payload.message || 'Не удалось получить статус worker’ов');
      render(payload.data || payload);
      host.dataset.updatedAt = new Date().toISOString();
    } catch (error) {
      if (!quiet) host.innerHTML = `<div class="worker-monitor-message error">${escapeHtml(error.message || 'Мониторинг недоступен')}</div>`;
    } finally {
      host.classList.remove('loading');
    }
  };

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) load({ quiet: true });
  });

  load();
  window.setInterval(() => {
    if (!document.hidden) load({ quiet: true });
  }, 15000);
})();