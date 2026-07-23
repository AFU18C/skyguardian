(() => {
  'use strict';

  const escapeHtml = value => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

  const setupTelegramQrLogin = () => {
    const qrButton = document.querySelector('[data-qr]');
    const modal = document.querySelector('#qrModal');
    const form = document.querySelector('[data-api-form]');
    const accountIdInput = document.querySelector('[data-tech-account-id]');
    const list = document.querySelector('[data-tech-list]');
    if (!qrButton || !modal || !form || !accountIdInput) return;

    const qrContainer = modal.querySelector('.qr-placeholder');
    const status = modal.querySelector('.qr-status');
    const statusTitle = status?.querySelector('strong');
    const statusText = status?.querySelector('small');
    const statusDot = status?.querySelector('.status-dot');
    const csrf = document.querySelector('[data-csrf]')?.dataset.csrf || '';
    const scope = list?.dataset.techScope || 'settings';
    const localKey = `skyguardian:${scope}:technical-accounts`;
    let polling = false;
    let generation = 0;
    let mobileLink = null;

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
      if (!accountIdInput.value) accountIdInput.value = globalThis.crypto?.randomUUID?.() || `account-${Date.now()}`;
      return accountIdInput.value;
    };

    const apiRequest = async (url, options = {}) => {
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.ok === false) throw new Error(result.message || 'Ошибка запроса.');
      return result;
    };

    const syncAccountsFromServer = async () => {
      try {
        const result = await apiRequest(`/technical-accounts.php?scope=${encodeURIComponent(scope)}`, {
          headers: { Accept: 'application/json' }
        });
        if (Array.isArray(result.items)) {
          localStorage.setItem(localKey, JSON.stringify(result.items));
          globalThis.dispatchEvent(new StorageEvent('storage', { key: localKey, newValue: JSON.stringify(result.items) }));
        }
      } catch (error) {
        console.warn('Technical account sync failed:', error);
      }
    };

    const qrPayload = operation => {
      const data = new FormData(form);
      return new URLSearchParams({
        _token: csrf,
        operation,
        account_id: ensureAccountId(),
        api_id: String(data.get('api_id') || '').trim(),
        api_hash: String(data.get('api_hash') || '').trim(),
      });
    };

    const requestQr = async (operation = 'status', password = '') => {
      const body = qrPayload(operation);
      if (password) body.set('password', password);
      return apiRequest('/telegram-qr.php', {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body,
      });
    };

    const saveConnectedAccount = async account => {
      const data = new FormData(form);
      const current = JSON.parse(localStorage.getItem(localKey) || '[]');
      const existing = Array.isArray(current) ? current.find(item => item.id === ensureAccountId()) : null;
      const telegramName = [account?.first_name, account?.last_name].filter(Boolean).join(' ').trim();
      const item = {
        id: ensureAccountId(),
        name: String(data.get('name') || '').trim(),
        api_id: String(data.get('api_id') || '').trim(),
        api_hash: String(data.get('api_hash') || '').trim(),
        connected: true,
        enabled: existing ? Boolean(existing.enabled) : true,
        telegram_id: String(account?.id || ''),
        telegram_name: telegramName || String(account?.username || ''),
        telegram_username: String(account?.username || ''),
        phone: String(account?.phone || ''),
        connected_at: new Date().toISOString(),
      };
      const body = new URLSearchParams({ _token: csrf, scope, item: JSON.stringify(item) });
      const result = await apiRequest('/technical-accounts.php', {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body,
      });
      localStorage.setItem(localKey, JSON.stringify(result.items || [item]));
    };

    const renderMobileButton = link => {
      mobileLink?.remove();
      mobileLink = null;
      if (!link || !/Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) return;
      const button = document.createElement('a');
      button.className = 'button primary full-button';
      button.textContent = 'Открыть в Telegram';
      button.href = link;
      button.style.marginTop = '12px';
      status.insertAdjacentElement('afterend', button);
      mobileLink = button;
    };

    const renderResult = async (result, currentGeneration) => {
      if (currentGeneration !== generation || !modal.classList.contains('open')) return false;
      if (result.logged_in) {
        await saveConnectedAccount(result.account || {});
        setStatus('success', 'Аккаунт подключён', 'Данные сохранены на сервере и доступны на всех устройствах');
        notify('Технический аккаунт Telegram подключён', 'success');
        setTimeout(() => window.location.reload(), 800);
        return false;
      }
      if (result.needs_2fa) {
        const password = globalThis.prompt(result.hint ? `Введите пароль 2FA. Подсказка: ${result.hint}` : 'Введите пароль 2FA');
        if (password === null) return false;
        return renderResult(await requestQr('2fa', password), currentGeneration);
      }
      if (typeof result.svg !== 'string' || !result.svg.includes('<svg')) throw new Error('Сервер не вернул QR-код.');
      qrContainer.innerHTML = result.svg;
      const svg = qrContainer.querySelector('svg');
      if (svg) {
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'QR-код для подключения Telegram');
        Object.assign(svg.style, { width: '100%', height: 'auto', display: 'block' });
      }
      renderMobileButton(result.link || '');
      const seconds = Math.max(0, Number(result.expires_in || 0));
      setStatus('pending', 'Ожидаем подтверждение', /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)
        ? 'Нажмите «Открыть в Telegram» и подтвердите новое устройство'
        : (seconds ? `Код действителен ещё ${seconds} сек.` : 'Код обновляется автоматически'));
      return true;
    };

    const poll = async currentGeneration => {
      if (polling || currentGeneration !== generation || !modal.classList.contains('open')) return;
      polling = true;
      try {
        const keepPolling = await renderResult(await requestQr('status'), currentGeneration);
        if (keepPolling && currentGeneration === generation && modal.classList.contains('open')) setTimeout(() => poll(currentGeneration), 2500);
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
      renderMobileButton('');
      setStatus('pending', 'Получаем QR-код', 'Устанавливаем защищённое соединение с Telegram…');
      qrContainer.innerHTML = '<div style="padding:5rem 1rem;text-align:center">Загрузка QR-кода…</div>';
      setTimeout(() => poll(generation), 0);
    });

    modal.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', () => {
      generation += 1;
      renderMobileButton('');
    }));

    syncAccountsFromServer().then(() => setTimeout(() => globalThis.location.reload(), 50));
  };

  setupTelegramQrLogin();

  const host = document.querySelector('[data-worker-monitor]');
  if (!host) return;
  const labels = { news: 'Новости', alerts: 'Воздушная тревога', running: 'Работает', idle: 'Ожидает', error: 'Ошибка', stale: 'Нет отклика', not_started: 'Не запускался' };
  const formatDuration = ms => Number(ms || 0) < 1000 ? `${Number(ms || 0)} мс` : `${(Number(ms || 0) / 1000).toFixed(1)} с`;
  const formatAge = sec => Number(sec || 0) < 60 ? `${Number(sec || 0)} сек назад` : `${Math.floor(Number(sec || 0) / 60)} мин назад`;
  const render = payload => {
    const workers = Array.isArray(payload?.workers) ? payload.workers : Object.values(payload?.workers || {});
    if (!workers.length) { host.innerHTML = '<div class="worker-monitor-message">Данные worker’ов пока недоступны.</div>'; return; }
    host.innerHTML = workers.map(worker => {
      const metrics = worker.metrics || {}, channels = worker.channels || {}, errors = Array.isArray(worker.errors) ? worker.errors : [];
      return `<section class="worker-monitor-card status-${escapeHtml(worker.status || 'not_started')}"><header><div><span class="worker-monitor-dot"></span><div><strong>${escapeHtml(labels[worker.scope] || worker.scope)}</strong><small>Telegram worker</small></div></div><span class="worker-monitor-status">${escapeHtml(labels[worker.status] || worker.status)}</span></header><div class="worker-monitor-grid"><div><span>Последний запуск</span><strong>${escapeHtml(worker.age_seconds == null ? 'Нет данных' : formatAge(worker.age_seconds))}</strong></div><div><span>Длительность</span><strong>${escapeHtml(formatDuration(metrics.duration_ms))}</strong></div><div><span>Обработано</span><strong>${Number(metrics.processed_count || 0)}</strong></div><div><span>Опубликовано</span><strong>${Number(metrics.published_count || 0)}</strong></div><div><span>Повторные попытки</span><strong>${Number(metrics.retry_count || 0)}</strong></div><div><span>Каналы с ошибками</span><strong>${Number(channels.error || 0)} / ${Number(channels.total || 0)}</strong></div></div><button class="worker-monitor-toggle" type="button" data-worker-errors-toggle>Последние ошибки <span>${errors.length}</span></button><div class="worker-monitor-details" hidden>${errors.length ? errors.map(error => `<article><strong>${escapeHtml(error.channel_name || error.channel_id || 'Worker')}</strong><time>${escapeHtml(error.at || '')}</time><p>${escapeHtml(error.message || 'Неизвестная ошибка')}</p></article>`).join('') : '<div class="worker-monitor-empty">Ошибок нет</div>'}</div></section>`;
    }).join('');
    host.querySelectorAll('[data-worker-errors-toggle]').forEach(button => button.addEventListener('click', () => { const details = button.nextElementSibling; details.hidden = !details.hidden; button.classList.toggle('open', !details.hidden); }));
  };
  const load = async ({ quiet = false } = {}) => {
    if (!quiet) host.classList.add('loading');
    try {
      const response = await fetch('/worker-status.php', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
      const payload = await response.json();
      if (!response.ok || payload.ok === false) throw new Error(payload.message || 'Не удалось получить статус worker’ов');
      render(payload.data || payload);
    } catch (error) {
      if (!quiet) host.innerHTML = `<div class="worker-monitor-message error">${escapeHtml(error.message || 'Мониторинг недоступен')}</div>`;
    } finally { host.classList.remove('loading'); }
  };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load({ quiet: true }); });
  load();
  setInterval(() => { if (!document.hidden) load({ quiet: true }); }, 15000);
})();
