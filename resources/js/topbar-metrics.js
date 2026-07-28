const metricsRoot = document.querySelector('[data-vps-metrics]');

if (metricsRoot) {
    const endpoint = metricsRoot.dataset.metricsUrl;
    const metricNames = ['cpu', 'memory', 'disk'];
    const summary = metricsRoot.querySelector('[data-vps-summary]');
    const updatedAt = metricsRoot.querySelector('[data-vps-updated-at]');

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

    refreshMetrics();
    window.setInterval(refreshMetrics, 15_000);
}
