(() => {
  'use strict';

  const setupTelegramQrLogin = () => {
    const qrButton = document.querySelector('[data-qr]');
    const modal = document.querySelector('#qrModal');
    const form = document.querySelector('[data-api-form]');
    const accountIdInput = document.querySelector('[data-tech-account-id]');
    if (!qrButton || !modal || !form || !accountIdInput) return;

    const qrContainer = modal.querySelector('.qr-placeholder');
    const status = modal.querySelector('.qr-status');
    const statusTitle = status?.querySelector('strong');
    const statusText = status?.querySelector('small');
    const statusDot = status?.querySelector('.status-dot');
    const csrf = document.querySelector('[data-csrf]')?.dataset.csrf || '';
    let polling = false;
    let generation = 0;

    const notify = (message, type = 'error') => {
      if (typeof globalThis.toast === 'function') globalThis.toast(message, type);
    };

    const setStatus = (state, title, text) => {
      if (statusTitle) statusTitle.textContent = title;
      if (statusText) statusText.textContent = text;
      if (statusDot) {
        statusDot.classList.remove('pending', 'success', 'error');
        statusDot.classList.add(state);
      }
    };

    const ensureAccountId = () => {
      if (!accountIdInput.value) {
        accountIdInput.value = globalThis.crypto?.randomUUID?.() || `account-${Date.now()}`;
      }
      return accountIdInput.value;
    };

    const payload = operation => {
      const data = new FormData(form);
      return new URLSearchParams({
        _token: csrf,
        operation,
        account_id: ensureAccountId(),
        api_id: String(data.get('api_id') || '').trim(),
        api_hash: String(data.get('api_hash') || '').trim(),
      });
    };

    const request = async (operation = 'status', password = '') => {
      const body = payload(operation);
      if (password) body.set('password', password);
      const response = await fetch('/telegram-qr.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        body,
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.ok === false) {
        throw new Error(result.message || 'Не удалось получить QR-код Telegram.');
      }
      return result;
    };

    const persistConnectedAccount = account => {
      const data = new FormData(form);
      const scope = document.querySelector('[data-tech-list]')?.dataset.techScope || 'settings';
      const key = `skyguardian:${scope}:technical-accounts`;
      let items = [];
      try {
        const parsed = JSON.parse(localStorage.getItem(key) || '[]');
        items = Array.isArray(parsed) ? parsed : [];
      } catch {
        items = [];
      }

      const id = ensureAccountId();
      const current = items.find(item => item.id === id);
      const name = [account?.first_name, account?.last_name].filter(Boolean).join(' ').trim();
      const item = {
        id,
        name: String(data.get('name') || '').trim(),
        api_id: String(data.get('api_id') || '').trim(),
        api_hash: String(data.get('api_hash') || '').trim(),
        connected: true,
        enabled: current ? Boolean(current.enabled) : true,
        telegram_id: String(account?.id || ''),
        telegram_name: name || String(account?.username || ''),
        telegram_username: String(account?.username || ''),
        phone: String(account?.phone || ''),
        connected_at: new Date().toISOString(),
      };
      const index = items.findIndex(existing => existing.id === id);
      if (index >= 0) items[index] = { ...items[index], ...item };
      else items.push(item);
      localStorage.setItem(key, JSON.stringify(items));
    };

    const renderResult = async (result, currentGeneration) => {
      if (currentGeneration !== generation || !modal.classList.contains('open')) return false;

      if (result.logged_in) {
        persistConnectedAccount(result.account || {});
        setStatus('success', 'Аккаунт подключён', 'Telegram-сессия сохранена на сервере');
        notify('Технический аккаунт Telegram подключён', 'success');
        window.setTimeout(() => window.location.reload(), 900);
        return false;
      }

      if (result.needs_2fa) {
        setStatus('pending', 'Требуется пароль 2FA', result.hint ? `Подсказка: ${result.hint}` : 'Введите пароль двухэтапной аутентификации');
        const password = globalThis.prompt(result.hint ? `Введите пароль 2FA. Подсказка: ${result.hint}` : 'Введите пароль двухэтапной аутентификации');
        if (password === null) return false;
        const completed = await request('2fa', password);
        return renderResult(completed, currentGeneration);
      }

      if (typeof result.svg === 'string' && result.svg.includes('<svg')) {
        qrContainer.innerHTML = result.svg;
        const svg = qrContainer.querySelector('svg');
        if (svg) {
          svg.setAttribute('role', 'img');
          svg.setAttribute('aria-label', 'QR-код для подключения Telegram');
          svg.style.width = '100%';
          svg.style.height = 'auto';
          svg.style.display = 'block';
        }
      } else {
        throw new Error('Сервер не вернул QR-код.');
      }
      const seconds = Math.max(0, Number(result.expires_in || 0));
      setStatus('pending', 'Ожидаем сканирование', seconds ? `Код действителен ещё ${seconds} сек.` : 'Код обновляется автоматически');
      return true;
    };

    const poll = async currentGeneration => {
      if (polling || currentGeneration !== generation || !modal.classList.contains('open')) return;
      polling = true;
      try {
        const result = await request('status');
        const keepPolling = await renderResult(result, currentGeneration);
        if (keepPolling && currentGeneration === generation && modal.classList.contains('open')) {
          window.setTimeout(() => poll(currentGeneration), 2500);
        }
      } catch (error) {
        setStatus('error', 'Ошибка подключения', error.message || 'Не удалось получить QR-код');
        notify(error.message || 'Не удалось получить QR-код');
      } finally {
        polling = false;
      }
    };

    qrButton.addEventListener('click', () => {
      const data = new FormData(form);
      if (!String(data.get('name') || '').trim() || !String(data.get('api_id') || '').trim() || !String(data.get('api_hash') || '').trim()) {
        notify('Сначала заполните название, API ID и API Hash');
        return;
      }
      generation += 1;
      setStatus('pending', 'Получаем QR-код', 'Устанавливаем защищённое соединение с Telegram…');
      if (qrContainer) qrContainer.innerHTML = '<div style="padding:5rem 1rem;text-align:center">Загрузка QR-кода…</div>';
      window.setTimeout(() => poll(generation), 0);
    });

    modal.querySelectorAll('[data-modal-close]').forEach(button => {
      button.addEventListener('click', () => { generation += 1; });
    });
  };

  setupTelegramQrLogin();

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
