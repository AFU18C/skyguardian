(() => {
  const form = document.querySelector('[data-source-form]');
  const list = document.querySelector('[data-source-list]');
  if (!form || !list) return;

  const modal = document.getElementById('sourceModal');
  const empty = document.querySelector('[data-source-empty]');
  const saveButton = document.querySelector('[data-source-save]');
  const deleteButton = document.querySelector('[data-source-delete]');
  const modalLabel = document.querySelector('[data-source-modal-label]');
  const accountSelect = form.elements.account;
  const formatSelect = form.elements.publication_format;
  const scope = list.dataset.sourceScope === 'news-sources' ? 'news' : 'alerts';

  let csrf = '';
  let channels = [];
  let accounts = [];

  const labels = {
    original: 'Оригинал полностью',
    text: 'Только текст',
    media: 'Только медиа',
    text_and_media: 'Текст и медиа'
  };

  const starts = {
    new: 'Только новые сообщения',
    last_5: 'Последние 5 сообщений',
    last_10: 'Последние 10 сообщений',
    last_20: 'Последние 20 сообщений'
  };

  const normalizeFormat = format => format === 'text_without_links' ? 'text' : format;

  const removeObsoleteFormat = () => {
    if (!formatSelect) return;
    const option = formatSelect.querySelector('option[value="text_without_links"]');
    if (option) option.remove();
  };

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
    const params = new URLSearchParams({ action, scope });
    const options = {
      method,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    };
    if (method === 'POST') {
      const body = new FormData();
      body.set('_token', csrf);
      body.set('scope', scope);
      Object.entries(fields).forEach(([key, value]) => {
        if (Array.isArray(value)) body.set(key, value.join(', '));
        else body.set(key, String(value ?? ''));
      });
      options.body = body;
    }
    const response = await fetch('/data-channel.php?' + params.toString(), options);
    let result;
    try { result = await response.json(); }
    catch { result = { ok: false, message: 'Сервер вернул некорректный ответ.' }; }
    if (!response.ok || !result.ok) throw new Error(result.message || 'Операция не выполнена.');
    return result;
  };

  const close = () => {
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  };

  const open = () => {
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  };

  const accountName = id => {
    const account = accounts.find(item => item.id === id);
    return account ? account.name : 'Аккаунт недоступен';
  };

  const statusLabel = channel => {
    if (!channel.enabled) return ['off', 'Остановлен'];
    if (channel.status === 'error') return ['off', 'Ошибка'];
    if (channel.status === 'active') return ['on', 'Работает'];
    return ['on', 'Ожидает запуска'];
  };

  const renderAccounts = selected => {
    if (!accountSelect) return;
    accountSelect.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = accounts.length ? 'Выберите аккаунт' : 'Нет подключённых аккаунтов';
    accountSelect.append(placeholder);
    accounts.forEach(account => {
      const option = document.createElement('option');
      option.value = account.id;
      option.textContent = account.name + (account.user_name ? ' — ' + account.user_name : '') + (account.enabled ? '' : ' (выключен)');
      option.disabled = !account.enabled;
      accountSelect.append(option);
    });
    accountSelect.value = selected || '';
  };

  const render = () => {
    list.querySelectorAll('[data-source-id]').forEach(card => card.remove());
    if (empty) empty.hidden = channels.length > 0;

    channels.forEach(channel => {
      channel.publication_format = normalizeFormat(channel.publication_format);
      const card = document.createElement('article');
      card.className = 'source-card';
      card.dataset.sourceId = channel.id;

      const [statusClass, statusText] = statusLabel(channel);
      const unit = channel.check_frequency_unit === 'hours' ? 'ч.' : 'сек.';
      const keywords = Array.isArray(channel.keywords) ? channel.keywords.join(', ') : String(channel.keywords || '');
      const lastCheck = channel.last_check_at ? new Date(channel.last_check_at).toLocaleString('ru-RU') : 'ещё не выполнялась';

      card.innerHTML = `
        <div class="source-card-icon">◉</div>
        <div class="source-card-info">
          <strong></strong>
          <span></span>
          <small></small>
          <small class="source-runtime-meta"></small>
        </div>
        <span class="status-pill ${statusClass}"><i></i>${statusText}</span>
        <label class="switch source-monitor-toggle" title="Включить или остановить мониторинг">
          <input type="checkbox" ${channel.enabled ? 'checked' : ''}><span></span>
        </label>
        <button class="source-edit-button" type="button" title="Редактировать" aria-label="Редактировать">✎</button>`;

      card.querySelector('.source-card-info strong').textContent = channel.name;
      card.querySelector('.source-card-info span').textContent = channel.source + ' → ' + channel.destination;
      card.querySelector('.source-card-info small').textContent =
        (labels[channel.publication_format] || channel.publication_format) + ' · ' +
        channel.check_frequency + ' ' + unit + ' · ' +
        (starts[channel.processing_start] || channel.processing_start);
      card.querySelector('.source-runtime-meta').textContent =
        accountName(channel.account) + ' · Проверка: ' + lastCheck + (keywords ? ' · Фильтр: ' + keywords : '');

      const toggle = card.querySelector('input[type="checkbox"]');
      toggle.addEventListener('change', async event => {
        event.stopImmediatePropagation();
        const next = toggle.checked;
        toggle.disabled = true;
        try {
          const result = await request('toggle', { id: channel.id, enabled: next ? '1' : '0' });
          Object.assign(channel, result.channel);
          render();
          showToast(result.message);
        } catch (error) {
          toggle.checked = !next;
          toggle.disabled = false;
          showToast(error.message, true);
        }
      }, true);

      card.querySelector('.source-edit-button').addEventListener('click', event => {
        event.stopImmediatePropagation();
        edit(channel.id);
      }, true);

      list.append(card);
    });
  };

  const reset = () => {
    form.reset();
    removeObsoleteFormat();
    form.elements.source_id.value = '';
    renderAccounts('');
    if (deleteButton) deleteButton.hidden = true;
    if (saveButton) saveButton.textContent = 'Добавить';
    if (modalLabel) modalLabel.textContent = 'ДОБАВЛЕНИЕ КАНАЛА';
    const customToggle = form.elements.custom_text_enabled;
    const editor = document.querySelector('[data-custom-text-editor]');
    if (customToggle) customToggle.checked = false;
    if (editor) editor.hidden = true;
  };

  const edit = id => {
    const channel = channels.find(item => item.id === id);
    if (!channel) return;
    reset();
    form.elements.source_id.value = channel.id;
    form.elements.name.value = channel.name;
    form.elements.source.value = channel.source;
    renderAccounts(channel.account);
    form.elements.destination.value = channel.destination;
    form.elements.publication_format.value = normalizeFormat(channel.publication_format);
    form.elements.check_frequency.value = channel.check_frequency;
    form.elements.check_frequency_unit.value = channel.check_frequency_unit;
    form.elements.processing_start.value = channel.processing_start;
    form.elements.keywords.value = Array.isArray(channel.keywords) ? channel.keywords.join(', ') : (channel.keywords || '');
    form.elements.stop_words.value = Array.isArray(channel.stop_words) ? channel.stop_words.join(', ') : (channel.stop_words || '');
    form.elements.custom_text_enabled.checked = Boolean(channel.custom_text_enabled);
    form.elements.custom_text_position.value = channel.custom_text_position || 'after';
    form.elements.custom_text.value = channel.custom_text || '';
    form.elements.custom_text_enabled.dispatchEvent(new Event('change'));
    form.elements.check_frequency_unit.dispatchEvent(new Event('change'));
    if (deleteButton) deleteButton.hidden = false;
    if (saveButton) saveButton.textContent = 'Сохранить';
    if (modalLabel) modalLabel.textContent = 'РЕДАКТИРОВАНИЕ КАНАЛА';
    open();
  };

  const values = () => {
    const data = Object.fromEntries(new FormData(form));
    return {
      id: data.source_id || '',
      name: data.name?.trim() || '',
      source: data.source?.trim() || '',
      account: data.account || '',
      destination: data.destination?.trim() || '',
      publication_format: normalizeFormat(data.publication_format || ''),
      check_frequency: data.check_frequency || '',
      check_frequency_unit: data.check_frequency_unit || 'seconds',
      processing_start: data.processing_start || '',
      keywords: data.keywords?.trim() || '',
      stop_words: data.stop_words?.trim() || '',
      custom_text_enabled: form.elements.custom_text_enabled.checked ? '1' : '0',
      custom_text_position: data.custom_text_position || 'after',
      custom_text: data.custom_text?.trim() || ''
    };
  };

  const save = async () => {
    if (!form.checkValidity()) {
      form.reportValidity();
      throw new Error('Заполните обязательные поля.');
    }
    if (!form.elements.account.value) throw new Error('Выберите технический аккаунт.');
    saveButton.disabled = true;
    const oldText = saveButton.textContent;
    saveButton.textContent = 'Сохраняю…';
    try {
      const result = await request('save', values());
      result.channel.publication_format = normalizeFormat(result.channel.publication_format);
      const index = channels.findIndex(item => item.id === result.channel.id);
      if (index >= 0) channels[index] = result.channel;
      else channels.push(result.channel);
      render();
      close();
      showToast(result.message);
    } finally {
      saveButton.disabled = false;
      saveButton.textContent = oldText;
    }
  };

  const bootstrap = async () => {
    try {
      removeObsoleteFormat();
      const result = await request('bootstrap', {}, 'GET');
      csrf = result.csrf;
      channels = Array.isArray(result.channels) ? result.channels.map(channel => ({
        ...channel,
        publication_format: normalizeFormat(channel.publication_format)
      })) : [];
      accounts = Array.isArray(result.accounts) ? result.accounts : [];
      localStorage.removeItem('skyguardian:' + (list.dataset.sourceScope || 'sources') + ':channels');
      renderAccounts('');
      render();
    } catch (error) {
      showToast(error.message, true);
    }
  };

  document.querySelector('[data-add-source]')?.addEventListener('click', event => {
    event.stopImmediatePropagation();
    if (channels.length >= 10) {
      showToast('В одном разделе можно добавить не более 10 каналов данных.', true);
      return;
    }
    reset();
    open();
  }, true);

  form.addEventListener('submit', async event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    try { await save(); }
    catch (error) { showToast(error.message, true); }
  }, true);

  deleteButton?.addEventListener('click', async event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    const id = form.elements.source_id.value;
    if (!id || !window.confirm('Удалить канал данных и остановить его мониторинг?')) return;
    deleteButton.disabled = true;
    try {
      const result = await request('delete', { id });
      channels = channels.filter(item => item.id !== id);
      render();
      close();
      showToast(result.message);
    } catch (error) {
      showToast(error.message, true);
    } finally {
      deleteButton.disabled = false;
    }
  }, true);

  bootstrap();
})();
