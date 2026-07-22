(() => {
  const list = document.querySelector('[data-source-list]');
  if (!list) return;

  const scope = list.dataset.sourceScope === 'news-sources' ? 'news' : 'alerts';
  const states = new Map();
  let refreshTimer = null;

  const formatDate = value => {
    if (!value) return 'ещё не выполнялся';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('ru-RU');
  };

  const paint = (card, state) => {
    if (!state) return;

    const pill = card.querySelector('.status-pill');
    if (pill) pill.hidden = true;

    const meta = card.querySelector('.source-runtime-meta');
    if (!meta) return;

    const parts = ['Последняя проверка: ' + formatDate(state.last_check_at)];
    if (state.last_publish_at) parts.push('Последняя публикация: ' + formatDate(state.last_publish_at));
    if (state.published_count) parts.push('Отправлено: ' + state.published_count);
    if (state.last_error) parts.push('Ошибка: ' + state.last_error);
    meta.textContent = parts.join(' · ');
  };

  const repaintAll = () => {
    states.forEach((state, id) => {
      const card = list.querySelector('[data-source-id="' + CSS.escape(id) + '"]');
      if (card) paint(card, state);
    });
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

      Object.entries(result.states || {}).forEach(([id, state]) => states.set(id, state));
      repaintAll();
    } catch {
      // Следующая попытка выполняется автоматически.
    } finally {
      clearTimeout(refreshTimer);
      refreshTimer = setTimeout(refresh, 5000);
    }
  };

  const observer = new MutationObserver(repaintAll);
  observer.observe(list, { childList: true });
  refresh();
})();
