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
    const syncMarkerKey = `skyguardian:${scope}:server-state`;
    let polling = false;
    let generation = 0;
    let pollTimer = 0;
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
    const readLocalItems = () => {
      try {
        const items = JSON.parse(localStorage.getItem(localKey) || '[]');
        return Array.isArray(items) ? items : [];
      } catch {
        return [];
      }
    };
    const apiRequest = async (url, options = {}, timeoutMs = 18000) => {
      const controller = new AbortController();
      const timer = window.setTimeout(() => controller.abort(), timeoutMs);
      try {
        const response = await fetch(url, {
          credentials: 'same-origin',
          cache: 'no-store',
          signal: controller.signal,
          ...options,
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || result.ok === false) throw new Error(result.message || 'Ошибка запроса.');
        return result;
      } catch (error) {
        if (error?.name === 'AbortError') throw new Error('Telegram отвечает слишком долго. Повторите попытку.');
        throw error;
      } finally {
        window.clearTimeout(timer);
      }
    };

    const saveItemToServer = async item => {
      const body = new URLSearchParams({ _token: csrf, scope, item: JSON.stringify(item) });
      return apiRequest('/technical-accounts.php', {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body,
      }, 10000);
    };

    const applyServerItems = items => {
      const normalized = Array.isArray(items) ? items : [];
      const next = JSON.stringify(normalized);
      const current = localStorage.getItem(localKey) || '[]';
      localStorage.setItem(localKey, next);
      if (next === current) {
        sessionStorage.setItem(syncMarkerKey, next);
        return false;
      }
      if (sessionStorage.getItem(syncMarkerKey) === next) return false;
      sessionStorage.setItem(syncMarkerKey, next);
      window.location.reload();
      return true;
    };

    const syncAccountsFromServer = async () => {
      try {
        const localItems = readLocalItems();
        const result = await apiRequest('/technical-accounts.php', { headers: { Accept: 'application/json' } }, 10000);
        const serverItems = Array.isArray(result.items) ? result.items : [];

        if (serverItems.length === 0 && localItems.length > 0) {
          let latest = [];
          for (const item of localItems) {
            const saved = await saveItemToServer(item);
            latest = Array.isArray(saved.items) ? saved.items : latest;
          }
          applyServerItems(latest);
          return;
        }

        applyServerItems(serverItems);
      } catch (error) {
        console.error('Technical account sync failed:', error);
        notify(error.message || 'Не удалось синхронизировать технические аккаунты');
      }
    };

    const qrPayload = operation => {
      const data = new FormData(form);
      return new URLSearchParams({
        _token: csrf,
        operation,
        scope,
        account_id: ensureAccountId(),
        name: String(data.get('name') || '').trim(),
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
      }, 22000);
    };
    const saveConnectedAccount = async account => {
      const data = new FormData(form);
      const localItems = readLocalItems();
      const id = ensureAccountId();
      const existing = localItems.find(item => item.id === id);
      const telegramName = [account?.first_name, account?.last_name].filter(Boolean).join(' ').trim();
      const item = {
        id,
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
      const result = await saveItemToServer(item);
      applyServerItems(result.items || [item]);
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
    const schedulePoll = currentGeneration => {
      window.clearTimeout(pollTimer);
      if (currentGeneration === generation && modal.classList.contains('open')) {
        pollTimer = window.setTimeout(() => poll(currentGeneration), 2500);
      }
    };
    const renderResult = async (result, currentGeneration) => {
      if (currentGeneration !== generation || !modal.classList.contains('open')) return false;
      if (result.logged_in) {
        await saveConnectedAccount(result.account || {});
        renderMobileButton('');
        setStatus('success', 'Аккаунт подключён', 'Сессия сохранена на сервере');
        notify('Технический аккаунт Telegram подключён', 'success');
        return false;
      }
      if (result.needs_2fa) {
        setStatus('pending', 'Требуется пароль 2FA', result.hint ? `Подсказка: ${result.hint}` : 'Введите пароль Telegram');
        const password = globalThis.prompt(result.hint ? `Введите пароль 2FA. Подсказка: ${result.hint}` : 'Введите пароль 2FA');
        if (password === null) return false;
        return renderResult(await requestQr('2fa', password), currentGeneration);
      }
      if (result.pending && !result.svg) {
        setStatus('pending', 'Завершаем подключение', 'Telegram подтверждает авторизацию…');
        return true;
      }
      if (typeof result.svg !== 'string' || !result.svg.includes('<svg')) throw new Error('Сервер не вернул QR-код.');
      qrContainer.innerHTML = result.svg;
      const svg = qrContainer.querySelector('svg');
      if (svg) Object.assign(svg.style, { width: '100%', height: 'auto', display: 'block' });
      renderMobileButton(result.link || '');
      const mobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
      setStatus('pending', 'Ожидаем подтверждение', mobile ? 'Нажмите «Открыть в Telegram»' : 'Отсканируйте код в Telegram');
      return true;
    };
    const poll = async currentGeneration => {
      if (polling || currentGeneration !== generation || !modal.classList.contains('open')) return;
      polling = true;
      try {
        const keepPolling = await renderResult(await requestQr('status'), currentGeneration);
        if (keepPolling) schedulePoll(currentGeneration);
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
      window.clearTimeout(pollTimer);
      renderMobileButton('');
      setStatus('pending', 'Получаем QR-код', 'Устанавливаем соединение с Telegram…');
      qrContainer.innerHTML = '<div style="padding:5rem 1rem;text-align:center">Загрузка QR-кода…</div>';
      window.setTimeout(() => poll(generation), 0);
    });
    modal.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', () => {
      generation += 1;
      window.clearTimeout(pollTimer);
      renderMobileButton('');
    }));
    syncAccountsFromServer();
  };

  setupTelegramQrLogin();

  const host = document.querySelector('[data-worker-monitor]');
  if (!host) return;
  const labels = { news: 'Новости', alerts: 'Воздушная тревога', running: 'Работает', idle: 'Ожидает', error: 'Ошибка', stale: 'Нет отклика', not_started: 'Не запускался' };
  const load = async ({ quiet = false } = {}) => {
    if (!quiet) host.classList.add('loading');
    try {
      const response = await fetch('/worker-status.php', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
      const payload = await response.json();
      if (!response.ok || payload.ok === false) throw new Error(payload.message || 'Не удалось получить статус worker’ов');
      const workers = Array.isArray(payload?.data?.workers) ? payload.data.workers : (Array.isArray(payload?.workers) ? payload.workers : Object.values(payload?.data?.workers || payload?.workers || {}));
      host.innerHTML = workers.map(worker => `<section class="worker-monitor-card status-${escapeHtml(worker.status || 'not_started')}"><header><div><span class="worker-monitor-dot"></span><div><strong>${escapeHtml(labels[worker.scope] || worker.scope)}</strong><small>Telegram worker</small></div></div><span class="worker-monitor-status">${escapeHtml(labels[worker.status] || worker.status)}</span></header></section>`).join('') || '<div class="worker-monitor-message">Данные worker’ов пока недоступны.</div>';
    } catch (error) {
      if (!quiet) host.innerHTML = `<div class="worker-monitor-message error">${escapeHtml(error.message || 'Мониторинг недоступен')}</div>`;
    } finally { host.classList.remove('loading'); }
  };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load({ quiet: true }); });
  load();
  setInterval(() => { if (!document.hidden) load({ quiet: true }); }, 15000);
})();