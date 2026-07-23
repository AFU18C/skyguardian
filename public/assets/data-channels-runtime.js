(() => {
  'use strict';
  const list = document.querySelector('[data-source-list]');
  const form = document.querySelector('[data-source-form]');
  if (!list || !form) return;

  const rawScope = list.dataset.sourceScope || '';
  const scope = rawScope.toLowerCase().includes('alert') ? 'alerts' : 'news';
  const localKey = `skyguardian:${rawScope || 'sources'}:channels`;
  const csrf = document.querySelector('[data-csrf]')?.dataset.csrf || document.querySelector('input[name="_token"]')?.value || '';
  const markerKey = `skyguardian:${scope}:channels-server-state`;

  const readLocal = () => {
    try {
      const value = JSON.parse(localStorage.getItem(localKey) || '[]');
      return Array.isArray(value) ? value : [];
    } catch { return []; }
  };
  const normalized = items => Array.isArray(items) ? items.filter(item => item && item.id) : [];
  const request = async (options = {}) => {
    const response = await fetch(`/data-channels.php?scope=${encodeURIComponent(scope)}`, {
      credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}, ...options,
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) throw new Error(payload.message || 'Ошибка синхронизации каналов');
    return payload;
  };
  const save = async items => {
    const body = new URLSearchParams({_token: csrf, scope, items: JSON.stringify(normalized(items))});
    return request({method:'POST', headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body});
  };
  const apply = items => {
    const next = JSON.stringify(normalized(items));
    const current = JSON.stringify(readLocal());
    localStorage.setItem(localKey, next);
    if (next !== current && sessionStorage.getItem(markerKey) !== next) {
      sessionStorage.setItem(markerKey, next);
      location.reload();
      return true;
    }
    sessionStorage.setItem(markerKey, next);
    return false;
  };
  const load = async () => {
    const payload = await request();
    const server = normalized(payload.items);
    const local = readLocal();
    if (server.length === 0 && local.length > 0) {
      const saved = await save(local);
      apply(saved.items || local);
      return;
    }
    apply(server);
  };
  const syncAfterLegacyHandler = () => queueMicrotask(() => save(readLocal()).catch(error => globalThis.toast?.(error.message, 'error')));

  form.addEventListener('submit', syncAfterLegacyHandler, false);
  document.querySelector('[data-source-delete]')?.addEventListener('click', syncAfterLegacyHandler, false);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load().catch(() => {}); });
  load().catch(error => globalThis.toast?.(error.message || 'Не удалось загрузить каналы данных', 'error'));
})();
