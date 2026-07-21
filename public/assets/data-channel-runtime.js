(() => {
  const list = document.querySelector('[data-source-list]');
  if (!list) return;

  const scope = list.dataset.sourceScope === 'news-sources' ? 'news' : 'alerts';
  let timer = null;

  const formatDate = value => {
    if (!value) return 'ещё не выполнялась';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('ru-RU');
  };

  const paint = (card, state) => {
    const pill = card.querySelector('.status-pill');
    const meta = card.querySelector('.source-runtime-meta');
    if (!pill || !state) return;

    let label = 'Ожидает запуска';
    let enabledClass = 'on';
    if (state.status === 'checking') label = 'Проверяет канал';
    if (state.status === 'active') label = 'Работает';
    if (state.status === 'paused') { label = 'Остановлен'; enabledClass = 'off'; }
    if (state.status === 'error') { label = 'Ошибка'; enabledClass = 'off'; }

    if (state.worker_seen_at) {
      const seen = new Date(state.worker_seen_at).getTime();
      if (!Number.isNaN(seen) && Date.now() - seen > 30000 && state.status !== 'paused') {
        label = 'Worker не отвечает';
        enabledClass = 'off';
      }
    }

    pill.classList.toggle('on', enabledClass === 'on');
    pill.classList.toggle('off', enabledClass === 'off');
    pill.innerHTML = '<i></i>' + label;
    pill.title = state.last_error || '';

    if (meta) {
      const parts = ['Проверка: ' + formatDate(state.last_check_at)];
      if (state.last_publish_at) parts.push('Публикация: ' + formatDate(state.last_publish_at));
      if (state.published_count) parts.push('Отправлено: ' + state.published_count);
      if (state.last_error) parts.push('Ошибка: ' + state.last_error);
      meta.textContent = parts.join(' · ');
    }
  };

  const refresh = async () => {
    try {
      const response = await fetch('/data-channel-status.php?scope=' + encodeURIComponent(scope) + '&_=' + Date.now(), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store'
      });
      const result = await response.json();
      if (!response.ok || !result.ok) return;
      Object.entries(result.states || {}).forEach(([id, state]) => {
        const card = list.querySelector('[data-source-id="' + CSS.escape(id) + '"]');
        if (card) paint(card, state);
      });
    } catch {
      // Повторяем автоматически, не блокируя интерфейс.
    } finally {
      clearTimeout(timer);
      timer = setTimeout(refresh, 3000);
    }
  };

  const observer = new MutationObserver(() => refresh());
  observer.observe(list, { childList: true });
  refresh();
})();
