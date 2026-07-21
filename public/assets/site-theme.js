(() => {
  const applyTheme = theme => {
    document.body.classList.remove('theme-radar', 'theme-shield');
    document.body.classList.add(theme === 'shield' ? 'theme-shield' : 'theme-radar');
  };

  const request = async (method = 'GET', body = null) => {
    const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    if (body) options.body = body;
    const response = await fetch('/site-theme.php', options);
    const result = await response.json().catch(() => ({ ok: false, message: 'Некорректный ответ сервера.' }));
    if (!response.ok || !result.ok) throw new Error(result.message || 'Не удалось изменить шаблон.');
    return result;
  };

  const showToast = (message, error = false) => {
    if (typeof window.toast === 'function') return window.toast(message, error ? 'error' : 'success');
    const stack = document.getElementById('toasts');
    if (!stack) return;
    const item = document.createElement('div');
    item.className = 'toast' + (error ? ' error' : '');
    item.textContent = message;
    stack.append(item);
    setTimeout(() => item.remove(), 3500);
  };

  const buildSelector = current => {
    const heading = [...document.querySelectorAll('.page-title h1')].find(node => node.textContent.trim() === 'Управление сайтом');
    if (!heading) return;
    const panel = heading.closest('.content')?.querySelector('.group-panel');
    if (!panel) return;

    const csrf = document.querySelector('[data-reboot-confirm]')?.dataset.csrf || '';
    panel.className = 'panel site-theme-panel';
    panel.innerHTML = `
      <h2>Шаблон оформления</h2>
      <p>Выберите внешний вид сайта и панели администратора. Изменение применяется сразу ко всем страницам.</p>
      <div class="theme-grid">
        <label class="theme-option" data-theme-option="radar">
          <input type="radio" name="site_theme" value="radar">
          <div class="theme-preview radar"></div>
          <strong>Radar — текущий</strong>
          <small>Классический тёмно-синий технический интерфейс с мягким свечением.</small>
        </label>
        <label class="theme-option" data-theme-option="shield">
          <input type="radio" name="site_theme" value="shield">
          <div class="theme-preview shield"></div>
          <strong>Shield — Повітряний Вартовий</strong>
          <small>Щит, радиолокационная сетка, глубокий синий фон и усиленные акценты.</small>
        </label>
      </div>
      <div class="theme-save-row"><button class="button primary" type="button" data-theme-save>Сохранить шаблон</button></div>`;

    const select = theme => {
      panel.querySelectorAll('[data-theme-option]').forEach(option => option.classList.toggle('selected', option.dataset.themeOption === theme));
      const input = panel.querySelector(`input[value="${theme}"]`);
      if (input) input.checked = true;
      applyTheme(theme);
    };
    select(current);

    panel.querySelectorAll('input[name="site_theme"]').forEach(input => input.addEventListener('change', () => select(input.value)));
    panel.querySelector('[data-theme-save]')?.addEventListener('click', async event => {
      const button = event.currentTarget;
      const selected = panel.querySelector('input[name="site_theme"]:checked')?.value || 'radar';
      const body = new FormData();
      body.set('_token', csrf);
      body.set('theme', selected);
      button.disabled = true;
      const oldText = button.textContent;
      button.textContent = 'Сохраняю…';
      try {
        const result = await request('POST', body);
        applyTheme(result.theme);
        showToast(result.message);
      } catch (error) {
        showToast(error.message, true);
      } finally {
        button.disabled = false;
        button.textContent = oldText;
      }
    });
  };

  const init = async () => {
    let theme = 'radar';
    try {
      const result = await request();
      theme = result.theme === 'shield' ? 'shield' : 'radar';
    } catch (_) {}
    applyTheme(theme);
    buildSelector(theme);
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
