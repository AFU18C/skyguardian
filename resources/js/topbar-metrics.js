const metricsRoot = document.querySelector('[data-vps-metrics]');

if (metricsRoot) {
    const endpoint = metricsRoot.dataset.metricsUrl;
    const metricNames = ['cpu', 'memory', 'disk'];

    const renderMetric = (name, value) => {
        const item = metricsRoot.querySelector(`[data-vps-metric="${name}"]`);
        const output = item?.querySelector('[data-vps-metric-value]');

        if (!item || !output) return;

        item.classList.remove('is-warning', 'is-critical', 'is-unavailable');

        if (!Number.isFinite(value)) {
            output.textContent = '--%';
            item.classList.add('is-unavailable');
            return;
        }

        const normalized = Math.max(0, Math.min(100, Math.round(value)));
        output.textContent = `${normalized}%`;

        if (normalized >= 90) {
            item.classList.add('is-critical');
        } else if (normalized >= 75) {
            item.classList.add('is-warning');
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
            metricNames.forEach((name) => renderMetric(name, Number(payload[name])));
        } catch {
            metricNames.forEach((name) => renderMetric(name, Number.NaN));
        }
    };

    refreshMetrics();
    window.setInterval(refreshMetrics, 15_000);
}
