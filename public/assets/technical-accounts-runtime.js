(() => {
  'use strict';

  const $ = (selector, root = document) => root.querySelector(selector);
  const list = $('[data-tech-list]');
  const form = $('[data-api-form]');
  const idInput = $('[data-tech-account-id]');
  const connectionModal = $('#connectionModal');
  const qrModal = $('#qrModal');
  if (!list || !form || !idInput) return;

  const scope = list.dataset.techScope || 'settings';
  const localKey = `skyguardian:${scope}:technical-accounts`;
  const csrf = $('[data-csrf]')?.dataset.csrf || $('input[name="_token"]')?.value || '';

  const notify = (message, type = 'error') => {
    if (typeof globalThis.toast === 'function') globalThis.toast(message, type);
  };

  const request = async (url, options = {}, timeout = 15000) => {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        signal: controller.signal,
        ...options,
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false) throw new Error(payload.message || 'Ошибка запроса');
      return payload;
    } finally {
      clearTimeout(timer);
    }
  };

  const normalizeItems = items => Array.isArray(items) ? items.filter(item => item && item.id) : [];

  const writeLocal = items => {
    localStorage.setItem(localKey, JSON.stringify(normalizeItems(items)));
  };

  const readLocal = () => {
    try { return normalizeItems(JSON.parse(localStorage.getItem(localKey) || '[]')); }
    catch { return []; }
  };

  const formatDate = value => {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('ru-RU');
  };

  const updateConnectionPanel = item => {
    const panel = $('[data-account]');
    if (!panel) return;
    const connected = Boolean(item?.connected);
    const closed = $('.account-closed', panel);
    const title = closed?.querySelector('strong');
    const text = closed?.querySelector('p');
    const qrButton = $('[data-qr]', panel);
    const toggle = panel.querySelector('.account-controls input[type="checkbox"]');
    const fields = panel.querySelectorAll('[data-account-details] input');

    if (title) title.textContent = connected ? 'Аккаунт подключён' : 'Аккаунт не подключён';
    if (text) text.textContent = connected
      ? (item.telegram_username ? `@${item.telegram_username}` : 'Telegram-сессия сохранена на сервере.')
      : 'Сохраните API, затем подключитесь по QR-коду.';
    if (qrButton) {
      qrButton.disabled = !item?.api_id || !item?.api_hash;
      qrButton.textContent = connected ? 'Переподключить по QR-коду' : 'Подключить по QR-коду';
    }
    if (toggle) {
      toggle.disabled = !connected;
      toggle.checked = connected && item.enabled !== false;
    }
    if (fields.length >= 4) {
      fields[0].value = connected ? (item.telegram_name || item.name || 'Подключён') : 'Не подключён';
      fields[1].value = connected ? (item.telegram_id || '—') : '—';
      fields[2].value = connected ? (item.phone || '—') : '—';
      fields[3].value = connected ? formatDate(item.connected_at) : '—';
    }
  };

  const currentEditorItem = items => items.find(item => item.id === idInput.value) || null;

  const applyServerState = items => {
    const normalized = normalizeItems(items);
    const before = JSON.stringify(readLocal());
    const after = JSON.stringify(normalized);
    writeLocal(normalized);
    updateConnectionPanel(currentEditorItem(normalized));
    window.dispatchEvent(new CustomEvent('skyguardian:technical-accounts', { detail: normalized }));
    return before !== after;
  };

  const loadServerState = async ({ reloadOnChange = true } = {}) => {
    const payload = await request('/technical-accounts.php', { headers: { Accept: 'application/json' } }, 10000);
    const changed = applyServerState(payload.items || []);
    if (changed && reloadOnChange) {
      const url = new URL(location.href);
      url.searchParams.set('_sgsync', Date.now().toString());
      location.replace(url.toString());
    }
    return payload.items || [];
  };

  const saveServerItem = async item => {
    const body = new URLSearchParams({ _token: csrf, item: JSON.stringify(item) });
    const payload = await request('/technical-accounts.php', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body,
    }, 10000);
    applyServerState(payload.items || []);
    return payload.items || [];
  };

  document.addEventListener('click', event => {
    const edit = event.target.closest('.technical-account-edit');
    if (!edit) return;
    setTimeout(() => updateConnectionPanel(currentEditorItem(readLocal())), 0);
  });

  $('[data-tech-save]')?.addEventListener('click', async event => {
    event.stopImmediatePropagation();
    const data = new FormData(form);
    const name = String(data.get('name') || '').trim();
    const apiId = String(data.get('api_id') || '').trim();
    const apiHash = String(data.get('api_hash') || '').trim();
    if (!name || !apiId || !apiHash) {
      notify('Заполните все поля Telegram API');
      form.reportValidity();
      return;
    }
    const id = idInput.value || crypto.randomUUID();
    idInput.value = id;
    const existing = readLocal().find(item => item.id === id) || {};
    const item = {
      ...existing,
      id,
      name,
      api_id: apiId,
      api_hash: apiHash,
      connected: Boolean(existing.connected),
      enabled: existing.enabled !== false,
    };
    try {
      await saveServerItem(item);
      connectionModal?.classList.remove('open');
      connectionModal?.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      notify('Сохранено', 'success');
      const url = new URL(location.href);
      url.searchParams.set('_sgsync', Date.now().toString());
      location.replace(url.toString());
    } catch (error) {
      notify(error.message || 'Не удалось сохранить аккаунт');
    }
  }, true);

  window.addEventListener('skyguardian:telegram-connected', async () => {
    try {
      await loadServerState({ reloadOnChange: false });
    } finally {
      qrModal?.classList.remove('open');
      connectionModal?.classList.remove('open');
      document.body.style.overflow = '';
      const url = new URL(location.href);
      url.searchParams.set('_sgsync', Date.now().toString());
      location.replace(url.toString());
    }
  });

  loadServerState().catch(error => notify(error.message || 'Не удалось загрузить технические аккаунты'));
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) loadServerState().catch(() => {});
  });
})();
