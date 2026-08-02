const metricsRoot = document.querySelector('[data-vps-metrics]');

if (metricsRoot) {
    const endpoint = metricsRoot.dataset.metricsUrl;
    const backupStatusEndpoint = metricsRoot.dataset.backupStatusUrl;
    const backupCreateEndpoint = metricsRoot.dataset.backupCreateUrl;
    const metricNames = ['cpu', 'memory', 'disk'];
    const summary = metricsRoot.querySelector('[data-vps-summary]');
    const updatedAt = metricsRoot.querySelector('[data-vps-updated-at]');
    const backup = metricsRoot.querySelector('[data-site-backup]');
    const backupState = backup?.querySelector('[data-backup-state]');
    const backupDescription = backup?.querySelector('[data-backup-description]');
    const backupLast = backup?.querySelector('[data-backup-last]');
    const backupCreate = backup?.querySelector('[data-backup-create]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    let currentBackup = {};

    const statusFor = (value) => {
        if (!Number.isFinite(value)) {
            return { className: 'is-unavailable', label: 'Данные недоступны' };
        }

        if (value >= 90) {
            return { className: 'is-critical', label: 'Критическая нагрузка' };
        }

        if (value >= 75) {
            return { className: 'is-warning', label: 'Повышенная нагрузка' };
        }

        return { className: '', label: 'Нагрузка в норме' };
    };

    const renderMetric = (name, value) => {
        const item = metricsRoot.querySelector(`[data-vps-metric="${name}"]`);
        const output = item?.querySelector('[data-vps-metric-value]');
        const statusOutput = item?.querySelector('[data-vps-metric-status]');
        const meter = item?.querySelector('[data-vps-meter-fill]');

        if (!item || !output || !statusOutput || !meter) return Number.NaN;

        item.classList.remove('is-warning', 'is-critical', 'is-unavailable');

        if (!Number.isFinite(value)) {
            output.textContent = '--%';
            statusOutput.textContent = 'Данные недоступны';
            meter.style.width = '0%';
            item.classList.add('is-unavailable');
            return Number.NaN;
        }

        const normalized = Math.max(0, Math.min(100, Math.round(value)));
        const status = statusFor(normalized);

        output.textContent = `${normalized}%`;
        statusOutput.textContent = status.label;
        meter.style.width = `${normalized}%`;

        if (status.className) item.classList.add(status.className);

        return normalized;
    };

    const renderSummary = (values) => {
        if (!summary) return;

        summary.classList.remove('is-warning', 'is-critical', 'is-unavailable');
        const text = summary.querySelector('strong');
        const finiteValues = values.filter(Number.isFinite);

        if (finiteValues.length !== metricNames.length) {
            summary.classList.add('is-unavailable');
            if (text) text.textContent = 'Данные недоступны';
            return;
        }

        const highest = Math.max(...finiteValues);
        const status = statusFor(highest);
        if (status.className) summary.classList.add(status.className);
        if (text) {
            text.textContent = highest >= 90
                ? 'Требуется внимание'
                : highest >= 75
                    ? 'Повышенная нагрузка'
                    : 'Система работает нормально';
        }
    };

    const refreshMetrics = async () => {
        if (!endpoint) return;

        try {
            const response = await fetch(endpoint, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const payload = await response.json();
            const values = metricNames.map((name) => renderMetric(name, Number(payload[name])));
            renderSummary(values);

            if (updatedAt) {
                updatedAt.textContent = new Intl.DateTimeFormat('ru-RU', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                }).format(new Date());
            }
        } catch {
            const values = metricNames.map((name) => renderMetric(name, Number.NaN));
            renderSummary(values);
            if (updatedAt) updatedAt.textContent = 'Не удалось обновить';
        }
    };

    const formatBytes = (bytes) => {
        if (!Number.isFinite(bytes) || bytes < 0) return '';

        const units = ['Б', 'КБ', 'МБ', 'ГБ'];
        let value = bytes;
        let unit = 0;

        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit += 1;
        }

        return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: unit > 1 ? 1 : 0 }).format(value)} ${units[unit]}`;
    };

    const formatBackupDate = (value) => {
        if (!value) return 'Ещё не создавалась';

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return 'Дата недоступна';

        return new Intl.DateTimeFormat('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(date);
    };

    const renderBackup = (payload) => {
        if (!backup || !backupState || !backupDescription || !backupLast || !backupCreate) return;

        currentBackup = { ...currentBackup, ...payload };
        const state = ['running', 'success', 'failed', 'idle'].includes(currentBackup.state)
            ? currentBackup.state
            : 'idle';
        const running = state === 'running';
        const rawSize = currentBackup.size_bytes;
        const size = rawSize === null || rawSize === undefined ? '' : formatBytes(Number(rawSize));

        backup.classList.toggle('is-running', running);
        backup.classList.toggle('is-failed', state === 'failed');
        backupState.textContent = running ? 'Создаётся…' : state === 'failed' ? 'Ошибка' : state === 'success' ? 'Готово' : 'Не создана';
        backupDescription.textContent = currentBackup.message || 'Данные о резервной копии недоступны';
        backupLast.textContent = `${formatBackupDate(currentBackup.last_backup_at)}${size ? ` · ${size}` : ''}`;
        backupCreate.disabled = running;
        backupCreate.classList.toggle('is-loading', running);
        backupCreate.querySelector('span').textContent = running ? 'Создание…' : 'Создать бэкап';
    };

    const refreshBackup = async () => {
        if (!backupStatusEndpoint || !backup) return;

        try {
            const response = await fetch(backupStatusEndpoint, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            renderBackup(await response.json());
        } catch {
            renderBackup({ state: 'idle', message: 'Не удалось получить состояние бэкапа' });
        }
    };

    backupCreate?.addEventListener('click', async () => {
        if (!backupCreateEndpoint || backupCreate.disabled) return;

        backupCreate.disabled = true;
        renderBackup({ state: 'running', message: 'Запускаем резервное копирование' });

        try {
            const response = await fetch(backupCreateEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) throw new Error(payload?.message || `HTTP ${response.status}`);
            renderBackup(payload);
            window.setTimeout(refreshBackup, 1200);
        } catch (error) {
            renderBackup({
                state: 'failed',
                message: error instanceof Error ? error.message : 'Не удалось запустить резервное копирование',
            });
        }
    });

    refreshMetrics();
    refreshBackup();
    window.setInterval(refreshMetrics, 15_000);
    window.setInterval(refreshBackup, 5_000);
}
