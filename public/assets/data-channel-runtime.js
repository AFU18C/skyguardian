(() => {
  const list = document.querySelector('[data-source-list]');
  if (!list) return;

  const scope = list.dataset.sourceScope === 'news-sources' ? 'news' : 'alerts';
  const states = new Map();
  let refreshTimer = null;
  let countdownTimer = null;

  const formatDate = value => {
    if (!value) return 'ещё не выполнялся';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('ru-RU');
  };

  const formatRemaining = milliseconds => {
    const totalSeconds = Math.max(0, Math.ceil(milliseconds / 1000));
    if (totalSeconds < 60) return totalSeconds + ' сек';

    const totalMinutes = Math.ceil(totalSeconds / 60);
    if (totalMinutes < 60) return totalMinutes + ' мин';

    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return minutes > 0 ? hours + ' ч ' + minutes + ' мин' : hours + ' ч';
  };

  const isWorkerUnresponsive = state => {
    if (!state.enabled || state.status === 'paused' || state.status === 'error') return false;

    const now = Date.now();
    const intervalMs = Math.max(1000, Number(state.interval_seconds || 60) * 1000);
    const toleranceMs = Math.max(90000, Math.min(300000, Math.ceil(intervalMs * 0.5)));
    const nextCheckAt = state.next_check_at ? new Date(state.next_check_at).getTime() : NaN;
    const seenAt = state.worker_seen_at ? new Date(state.worker_seen_at).getTime() : NaN;

    if (state.status === 'checking') {
      return !Number.isNaN(seenAt) && now > seenAt + 120000;
    }

    if (!Number.isNaN(nextCheckAt)) {
      return now > nextCheckAt + toleranceMs;
    }

    return !Number.isNaN(seenAt) && now > seenAt + intervalMs + toleranceMs;
  };

  const paint = (card, state) => {
    const pill = card.querySelector('.status-pill');
    const meta = card.querySelector('.source-runtime-meta');
    if (!pill || !state) return;

    let label = 'Работает';
    let enabledClass = 'on';

    if (state.status === 'checking') label = 'Проверяет канал';
    if (state.status === 'paused') {
      label = 'Остановлен';
      enabledClass = 'off';
    }
    if (state.status === 'error') {
      label = 'Ошибка';
      enabledClass = 'off';
    }
    if (isWorkerUnresponsive(state)) {
      label = 'Воркер не отвечает';
      enabledClass = 'off';
    }

    pill.classList.toggle('on', enabledClass === 'on');
    pill.classList.toggle('off', enabledClass === 'off');
    pill.innerHTML = '<i></i>' + label;
    pill.title = state.last_error || '';

    if (!meta) return;

    const parts = [];
    if (enabledClass === 'on' && state.next_check_at) {
      const nextCheck = new Date(state.next_check_at).getTime();
      if (!Number.isNaN(nextCheck)) {
        parts.push('Следующая проверка через ' + formatRemaining(nextCheck - Date.now()));
      }
    }

    parts.push('Последний мониторинг: ' + formatDate(state.last_check_at));
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
      // Повторяем автоматически, не блокируя интерфейс.
    } finally {
      clearTimeout(refreshTimer);
      refreshTimer = setTimeout(refresh, 3000);
    }
  };

  const observer = new MutationObserver(() => repaintAll());
  observer.observe(list, { childList: true });

  countdownTimer = setInterval(repaintAll, 1000);
  window.addEventListener('beforeunload', () => clearInterval(countdownTimer), { once: true });
  refresh();
})();