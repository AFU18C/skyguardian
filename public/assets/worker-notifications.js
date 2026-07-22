(() => {
  const root = document.querySelector('[data-worker-notifications]');
  if (!root) return;

  const endpoint = '/worker-notifications.php';
  const form = root.querySelector('[data-notification-form]');
  const enabled = root.querySelector('[name="enabled"]');
  const token = root.querySelector('[name="bot_token"]');
  const chatId = root.querySelector('[name="chat_id"]');
  const cooldown = root.querySelector('[name="cooldown_seconds"]');
  const status = root.querySelector('[data-notification-status]');
  const journal = root.querySelector('[data-notification-journal]');
  const saveButton = root.querySelector('[data-notification-save]');
  const testButton = root.querySelector('[data-notification-test]');
  const csrf = root.dataset.csrf || '';

  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  })[char]);

  function setBusy(button, busy, text) {
    if (!button) return;
    if (!button.dataset.originalText) button.dataset.originalText = button.textContent;
    button.disabled = busy;
    button.textContent = busy ? text : button.dataset.originalText;
  }

  function showStatus(config) {
    if (!status) return;
    const configured = Boolean(config?.configured);
    const active = Boolean(config?.enabled);
    status.className = 'notification-state ' + (active ? 'active' : configured ? 'ready' : 'inactive');
    status.innerHTML = `<i></i><div><strong>${active ? 'Уведомления включены' : configured ? 'Настройки сохранены' : 'Не настроено'}</strong><span>${configured ? `Chat ID: ${escapeHtml(config.chat_id_masked || 'скрыт')} · cooldown ${Number(config.cooldown_seconds || 900)} сек.` : 'Укажите Bot Token и Chat ID.'}</span></div>`;
  }

  function showJournal(items) {
    if (!journal) return;
    if (!Array.isArray(items) || items.length === 0) {
      journal.innerHTML = '<div class="notification-empty">Событий пока нет.</div>';
      return;
    }
    journal.innerHTML = items.map(item => {
      const state = ['sent', 'failed', 'suppressed'].includes(item.status) ? item.status : 'suppressed';
      return `<article class="notification-log ${state}">
        <div><strong>${escapeHtml(item.title || item.event || 'Событие')}</strong><time>${escapeHtml(item.created_at || '')}</time></div>
        <p>${escapeHtml(item.message || '')}</p>
        <span>${state === 'sent' ? 'Отправлено' : state === 'failed' ? 'Ошибка доставки' : 'Подавлено cooldown'}</span>
      </article>`;
    }).join('');
  }

  async function request(method = 'GET', data = null) {
    const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (data) {
      const body = new URLSearchParams({ _token: csrf, ...data });
      options.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
      options.body = body;
    }
    const response = await fetch(endpoint, options);
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.ok) throw new Error(result.message || 'Не удалось выполнить запрос');
    return result;
  }

  async function load() {
    try {
      const result = await request();
      const config = result.config || {};
      enabled.checked = Boolean(config.enabled);
      chatId.value = config.chat_id || '';
      cooldown.value = String(config.cooldown_seconds || 900);
      token.value = '';
      token.placeholder = config.has_bot_token ? 'Токен сохранён — оставьте пустым, чтобы не менять' : '123456789:AA...';
      showStatus(config);
      showJournal(result.journal || []);
    } catch (error) {
      showStatus(null);
      if (journal) journal.innerHTML = `<div class="notification-empty error">${escapeHtml(error.message)}</div>`;
    }
  }

  form?.addEventListener('submit', async event => {
    event.preventDefault();
    setBusy(saveButton, true, 'Сохраняю…');
    try {
      await request('POST', {
        operation: 'save',
        enabled: enabled.checked ? '1' : '0',
        bot_token: token.value.trim(),
        chat_id: chatId.value.trim(),
        cooldown_seconds: cooldown.value
      });
      globalThis.toast?.('Настройки уведомлений сохранены');
      await load();
    } catch (error) {
      globalThis.toast?.(error.message || 'Не удалось сохранить', 'error');
    } finally {
      setBusy(saveButton, false, '');
    }
  });

  testButton?.addEventListener('click', async () => {
    setBusy(testButton, true, 'Отправляю…');
    try {
      await request('POST', { operation: 'test' });
      globalThis.toast?.('Тестовое уведомление отправлено');
      await load();
    } catch (error) {
      globalThis.toast?.(error.message || 'Тест не отправлен', 'error');
    } finally {
      setBusy(testButton, false, '');
    }
  });

  load();
  setInterval(load, 30000);
})();
