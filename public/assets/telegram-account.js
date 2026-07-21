(() => {
  const form = document.querySelector('[data-api-form]');
  const list = document.querySelector('[data-tech-list]');
  if (!form || !list) return;

  const modal = document.getElementById('connectionModal');
  const qrModal = document.getElementById('qrModal');
  const idInput = document.querySelector('[data-tech-account-id]');
  const checkButton = document.querySelector('[data-api-check]');
  const qrButton = document.querySelector('[data-qr]');
  const saveButton = document.querySelector('[data-tech-save]');
  const deleteButton = document.querySelector('[data-tech-delete]');
  const empty = document.querySelector('[data-tech-empty]');
  const apiStatus = form.closest('.api-panel')?.querySelector('.status-pill');
  const accountPanel = document.querySelector('[data-account]');
  const qrPlaceholder = qrModal?.querySelector('.qr-placeholder');
  const qrStatusStrong = qrModal?.querySelector('.qr-status strong');
  const qrStatusSmall = qrModal?.querySelector('.qr-status small');
  const qrStatusDot = qrModal?.querySelector('.qr-status .status-dot');
  const scope = list.dataset.techScope === 'news-settings' ? 'news' : 'alerts';

  let csrf = '';
  let accounts = [];
  let waitTimer = null;
  let activeQrId = '';
  let twoFactorModal = null;

  const showToast = (message, error = false) => {
    if (typeof window.toast === 'function') {
      window.toast(message, error ? 'error' : 'success');
      return;
    }
    const stack = document.getElementById('toasts');
    if (!stack) return;
    const item = document.createElement('div');
    item.className = 'toast' + (error ? ' error' : '');
    item.textContent = message;
    stack.append(item);
    setTimeout(() => item.remove(), 3500);
  };

  const request = async (action, fields = {}, method = 'POST') => {
    const options = { method, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    const params = new URLSearchParams({ action, scope });
    const url = '/telegram-account.php?' + params.toString();
    if (method === 'POST') {
      const body = new FormData();
      body.set('_token', csrf);
      body.set('scope', scope);
      Object.entries(fields).forEach(([key, value]) => body.set(key, String(value ?? '')));
      options.body = body;
    }
    const response = await fetch(url, options);
    let result;
    try { result = await response.json(); } catch { result = { ok: false, message: 'Сервер вернул некорректный ответ.' }; }
    if (!response.ok || !result.ok) throw new Error(result.message || 'Операция не выполнена.');
    return result;
  };

  const closeModal = target => {
    if (!target) return;
    target.classList.remove('open');
    target.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.modal.open')) document.body.style.overflow = '';
  };

  const openModal = target => {
    if (!target) return;
    target.classList.add('open');
    target.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  };

  const ensureTwoFactorModal = () => {
    if (twoFactorModal) return twoFactorModal;
    const wrapper = document.createElement('div');
    wrapper.className = 'modal';
    wrapper.id = 'telegramTwoFactorModal';
    wrapper.setAttribute('aria-hidden', 'true');
    wrapper.innerHTML = `
      <div class="modal-backdrop" data-2fa-cancel></div>
      <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="telegramTwoFactorTitle">
        <button class="modal-close" type="button" data-2fa-cancel aria-label="Закрыть">×</button>
        <span class="step-label">ПОДТВЕРЖДЕНИЕ TELEGRAM</span>
        <h2 id="telegramTwoFactorTitle">Двухэтапная аутентификация</h2>
        <p>Введите пароль Telegram, чтобы завершить подключение технического аккаунта.</p>
        <form class="form-grid" data-2fa-form>
          <label class="full">
            <span>Пароль</span>
            <div class="input-action">
              <input name="password" type="password" autocomplete="current-password" required placeholder="Введите пароль">
              <button type="button" data-2fa-password aria-label="Показать или скрыть пароль">◉</button>
            </div>
            <small class="form-hint" data-2fa-hint hidden></small>
            <small class="form-hint" data-2fa-error hidden></small>
          </label>
          <div class="modal-actions full">
            <button class="button ghost" type="button" data-2fa-cancel>Отмена</button>
            <button class="button primary" type="submit" data-2fa-submit>Подтвердить</button>
          </div>
        </form>
      </div>`;
    document.body.append(wrapper);
    twoFactorModal = wrapper;
    wrapper.querySelectorAll('[data-2fa-cancel]').forEach(button => button.addEventListener('click', () => closeModal(wrapper)));
    wrapper.querySelector('[data-2fa-password]')?.addEventListener('click', () => {
      const input = wrapper.querySelector('input[name="password"]');
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
    });
    return wrapper;
  };

  const askTwoFactorPassword = hint => new Promise(resolve => {
    const dialog = ensureTwoFactorModal();
    const form2fa = dialog.querySelector('[data-2fa-form]');
    const input = dialog.querySelector('input[name="password"]');
    const hintNode = dialog.querySelector('[data-2fa-hint]');
    const errorNode = dialog.querySelector('[data-2fa-error]');
    const submit = dialog.querySelector('[data-2fa-submit]');

    if (hintNode) {
      hintNode.hidden = !hint;
      hintNode.textContent = hint ? 'Подсказка Telegram: ' + hint : '';
    }
    if (errorNode) {
      errorNode.hidden = true;
      errorNode.textContent = '';
    }
    if (input) {
      input.value = '';
      input.type = 'password';
    }
    if (submit) {
      submit.disabled = false;
      submit.textContent = 'Подтвердить';
    }

    const cancelButtons = dialog.querySelectorAll('[data-2fa-cancel]');
    const cleanup = value => {
      form2fa?.removeEventListener('submit', onSubmit);
      cancelButtons.forEach(button => button.removeEventListener('click', onCancel));
      closeModal(dialog);
      resolve(value);
    };
    const onCancel = event => {
      event.preventDefault();
      cleanup(null);
    };
    const onSubmit = event => {
      event.preventDefault();
      const value = input?.value || '';
      if (!value) {
        if (errorNode) {
          errorNode.hidden = false;
          errorNode.textContent = 'Введите пароль двухэтапной аутентификации.';
        }
        input?.focus();
        return;
      }
      cleanup(value);
    };

    form2fa?.addEventListener('submit', onSubmit);
    cancelButtons.forEach(button => button.addEventListener('click', onCancel));
    openModal(dialog);
    setTimeout(() => input?.focus(), 50);
  });

  const setApiState = (ready, text = ready ? 'Проверено' : 'Не настроено') => {
    if (!apiStatus) return;
    apiStatus.classList.toggle('on', ready);
    apiStatus.classList.toggle('off', !ready);
    apiStatus.innerHTML = '<i></i>' + text;
    if (qrButton) qrButton.disabled = !ready;
  };

  const setAccountPanel = account => {
    if (!accountPanel) return;
    const connected = Boolean(account?.connected);
    const closed = accountPanel.querySelector('.account-closed');
    const title = closed?.querySelector('strong');
    const note = closed?.querySelector('p');
    if (title) title.textContent = connected ? 'Аккаунт подключён' : 'Аккаунт не подключён';
    if (note) note.textContent = connected ? ((account.user?.name || account.user?.username || 'Telegram') + ' готов к работе.') : 'Сохраните API, затем подключитесь по QR-коду.';
    const toggle = accountPanel.querySelector('.account-controls input[type="checkbox"]');
    if (toggle) {
      toggle.disabled = !account;
      toggle.checked = Boolean(account?.enabled);
    }
    const inputs = accountPanel.querySelectorAll('.details-grid input');
    if (inputs[0]) inputs[0].value = connected ? (account.user?.name || account.user?.username || 'Telegram') : 'Не подключён';
    if (inputs[1]) inputs[1].value = connected ? (account.user?.id || '—') : '—';
    if (inputs[2]) inputs[2].value = connected ? (account.user?.phone ? '+' + account.user.phone.replace(/^\+/, '') : 'Скрыт') : '—';
    if (inputs[3]) inputs[3].value = account?.connected_at ? new Date(account.connected_at).toLocaleString('ru-RU') : '—';
  };

  const render = () => {
    list.querySelectorAll('[data-tech-account-card]').forEach(node => node.remove());
    if (empty) empty.hidden = accounts.length > 0;
    accounts.forEach(account => {
      const card = document.createElement('article');
      card.className = 'technical-account-card';
      card.dataset.techAccountCard = account.id;
      card.innerHTML = `
        <div class="technical-account-icon">♟</div>
        <div class="technical-account-info"><strong></strong><span></span><small></small></div>
        <span class="status-pill ${account.enabled ? 'on' : 'off'}"><i></i>${account.enabled ? 'Включён' : 'Выключен'}</span>
        <label class="switch technical-account-toggle"><input type="checkbox" ${account.enabled ? 'checked' : ''}><span></span></label>
        <button class="technical-account-edit" type="button" aria-label="Редактировать">✎</button>`;
      card.querySelector('.technical-account-info strong').textContent = account.name;
      card.querySelector('.technical-account-info span').textContent = 'API ID: ' + account.api_id;
      card.querySelector('.technical-account-info small').textContent = account.connected ? 'Технический аккаунт подключён' : 'Технический аккаунт не подключён';
      card.querySelector('input[type="checkbox"]').addEventListener('change', async event => {
        event.stopImmediatePropagation();
        event.target.disabled = true;
        try {
          const result = await request('toggle', { id: account.id, enabled: event.target.checked ? '1' : '0' });
          Object.assign(account, result.account);
          render();
          showToast(result.message);
        } catch (error) {
          event.target.checked = !event.target.checked;
          event.target.disabled = false;
          showToast(error.message, true);
        }
      }, true);
      card.querySelector('.technical-account-edit').addEventListener('click', event => {
        event.stopImmediatePropagation();
        form.reset();
        idInput.value = account.id;
        form.elements.name.value = account.name;
        form.elements.api_id.value = account.api_id;
        form.elements.api_hash.value = '';
        form.elements.api_hash.placeholder = account.has_api_hash ? 'API Hash сохранён на сервере' : 'Введите API Hash';
        if (deleteButton) deleteButton.hidden = false;
        setApiState(true, 'Настроено');
        setAccountPanel(account);
        openModal(modal);
      }, true);
      list.append(card);
    });
  };

  const bootstrap = async () => {
    try {
      const result = await request('bootstrap', {}, 'GET');
      csrf = result.csrf;
      accounts = Array.isArray(result.accounts) ? result.accounts : [];
      localStorage.removeItem('skyguardian:' + (list.dataset.techScope || 'settings') + ':technical-accounts');
      render();
    } catch (error) {
      showToast(error.message, true);
    }
  };

  const currentValues = () => ({
    id: idInput?.value || '',
    name: form.elements.name?.value?.trim() || '',
    api_id: form.elements.api_id?.value?.trim() || '',
    api_hash: form.elements.api_hash?.value?.trim() || ''
  });

  const checkApi = async () => {
    const values = currentValues();
    if (!values.name || !values.api_id || (!values.api_hash && !values.id)) {
      form.reportValidity();
      throw new Error('Заполните все поля Telegram API.');
    }
    checkButton.disabled = true;
    checkButton.textContent = 'Проверяем…';
    try {
      const result = await request('check', values);
      const account = result.account;
      idInput.value = account.id;
      const index = accounts.findIndex(item => item.id === account.id);
      if (index >= 0) accounts[index] = account; else accounts.push(account);
      setApiState(true, 'Проверено');
      setAccountPanel(account);
      render();
      showToast(result.message);
      return account;
    } finally {
      checkButton.disabled = false;
      checkButton.textContent = 'Проверить API';
    }
  };

  const updateQr = async result => {
    if (result.logged_in) {
      if (qrStatusStrong) qrStatusStrong.textContent = 'Telegram подключён';
      if (qrStatusSmall) qrStatusSmall.textContent = 'Сессия сохранена на сервере';
      qrStatusDot?.classList.remove('pending');
      qrStatusDot?.classList.add('online');
      const index = accounts.findIndex(item => item.id === result.account.id);
      if (index >= 0) accounts[index] = result.account;
      render();
      setAccountPanel(result.account);
      showToast(result.message);
      clearTimeout(waitTimer);
      waitTimer = setTimeout(() => closeModal(qrModal), 1200);
      return true;
    }
    if (result.needs_2fa) {
      const password = await askTwoFactorPassword(result.hint || '');
      if (password === null) return true;
      try {
        const passwordResult = await request('password', { id: activeQrId, password });
        return updateQr(passwordResult);
      } catch (error) {
        showToast(error.message, true);
        return updateQr(result);
      }
    }
    if (result.svg && qrPlaceholder) {
      qrPlaceholder.innerHTML = result.svg;
      const svg = qrPlaceholder.querySelector('svg');
      if (svg) { svg.setAttribute('width', '100%'); svg.setAttribute('height', 'auto'); }
    }
    return false;
  };

  const waitQr = async () => {
    if (!activeQrId || !qrModal?.classList.contains('open')) return;
    try {
      const result = await request('wait', { id: activeQrId });
      if (!(await updateQr(result))) waitTimer = setTimeout(waitQr, 300);
    } catch (error) {
      if (qrStatusSmall) qrStatusSmall.textContent = error.message;
      waitTimer = setTimeout(waitQr, 2500);
    }
  };

  form.addEventListener('submit', async event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    try { await checkApi(); } catch (error) { showToast(error.message, true); }
  }, true);

  checkButton?.addEventListener('click', async event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    try { await checkApi(); } catch (error) { showToast(error.message, true); }
  }, true);

  qrButton?.addEventListener('click', async event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    try {
      let account = accounts.find(item => item.id === idInput.value);
      if (!account) account = await checkApi();
      activeQrId = account.id;
      if (qrStatusStrong) qrStatusStrong.textContent = 'Ожидаем сканирование';
      if (qrStatusSmall) qrStatusSmall.textContent = 'Код обновляется автоматически';
      qrStatusDot?.classList.add('pending');
      openModal(qrModal);
      const result = await request('qr', { id: activeQrId });
      if (!(await updateQr(result))) waitQr();
    } catch (error) {
      closeModal(qrModal);
      showToast(error.message, true);
    }
  }, true);

  saveButton?.addEventListener('click', async event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    try {
      await checkApi();
      closeModal(modal);
      showToast('Настройки сохранены на сервере.');
    } catch (error) { showToast(error.message, true); }
  }, true);

  deleteButton?.addEventListener('click', async event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    const id = idInput.value;
    if (!id || !window.confirm('Удалить технический аккаунт и его Telegram-сессию?')) return;
    try {
      const result = await request('delete', { id });
      accounts = accounts.filter(item => item.id !== id);
      render();
      closeModal(modal);
      showToast(result.message);
    } catch (error) { showToast(error.message, true); }
  }, true);

  accountPanel?.querySelector('.account-controls input[type="checkbox"]')?.addEventListener('change', async event => {
    event.stopImmediatePropagation();
    const id = idInput.value;
    if (!id) return;
    try {
      const result = await request('toggle', { id, enabled: event.target.checked ? '1' : '0' });
      const index = accounts.findIndex(item => item.id === id);
      if (index >= 0) accounts[index] = result.account;
      render();
      setAccountPanel(result.account);
      showToast(result.message);
    } catch (error) {
      event.target.checked = !event.target.checked;
      showToast(error.message, true);
    }
  }, true);

  document.querySelector('[data-add-connection]')?.addEventListener('click', () => {
    setTimeout(() => {
      idInput.value = '';
      form.elements.api_hash.placeholder = 'Введите API Hash';
      setApiState(false);
      setAccountPanel(null);
    }, 0);
  }, true);

  qrModal?.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', () => {
    clearTimeout(waitTimer);
    activeQrId = '';
  }, true));

  bootstrap();
})();
