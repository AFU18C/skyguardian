const onReady = (callback) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
        callback();
    }
};

onReady(() => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    const showToast = ({ type = 'info', title = 'SkyGuardian', message = '' } = {}) => {
        document.querySelector('[data-group-channel-toast]')?.remove();

        const toast = document.createElement('div');
        toast.className = `sg-toast sg-toast-${type}`;
        toast.dataset.groupChannelToast = '';

        const icon = document.createElement('div');
        icon.className = 'sg-toast-icon';
        icon.textContent = type === 'success' ? '✓' : type === 'error' ? '!' : 'i';

        const content = document.createElement('div');
        content.className = 'sg-toast-content';
        const heading = document.createElement('strong');
        heading.textContent = title;
        const text = document.createElement('p');
        text.textContent = message;
        content.append(heading, text);

        const close = document.createElement('button');
        close.type = 'button';
        close.setAttribute('aria-label', 'Закрыть уведомление');
        close.textContent = '×';
        close.addEventListener('click', () => toast.remove());

        toast.append(icon, content, close);
        document.body.append(toast);
        window.setTimeout(() => toast.remove(), 5000);
    };

    const setModuleExpanded = (card, expanded) => {
        const panel = card?.querySelector('[data-module-panel]');
        const toggle = card?.querySelector('[data-module-toggle]');
        if (!panel || !toggle) return;

        panel.hidden = !expanded;
        card.classList.toggle('is-open', expanded);
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.textContent = expanded ? '⌃' : '⌄';
    };

    document.querySelectorAll('[data-module-card]').forEach((card) => {
        const checkbox = card.querySelector('[data-module-checkbox]');
        const toggle = card.querySelector('[data-module-toggle]');
        const panel = card.querySelector('[data-module-panel]');

        const syncAvailability = () => {
            if (!checkbox) return;
            if (toggle) toggle.hidden = !checkbox.checked;
            if (!checkbox.checked) setModuleExpanded(card, false);
        };

        toggle?.addEventListener('click', () => {
            if (!checkbox?.checked || !panel) return;
            setModuleExpanded(card, panel.hidden);
        });

        checkbox?.addEventListener('change', async () => {
            const enabled = checkbox.checked;
            const url = checkbox.dataset.moduleUrl;
            if (!url) return;

            checkbox.disabled = true;
            card.classList.add('is-saving');
            syncAvailability();

            try {
                const response = await fetch(url, {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ enabled }),
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message || 'Не удалось сохранить функцию.');
                }

                checkbox.checked = Boolean(payload.enabled);
                syncAvailability();
                showToast(payload.toast);
            } catch (error) {
                checkbox.checked = !enabled;
                syncAvailability();
                showToast({
                    type: 'error',
                    title: 'Функция не сохранена',
                    message: error instanceof Error ? error.message : 'Произошла ошибка сохранения.',
                });
            } finally {
                checkbox.disabled = false;
                card.classList.remove('is-saving');
            }
        });

        syncAvailability();
    });

    document.querySelectorAll('[data-modal] form').forEach((form) => {
        form.addEventListener('submit', () => {
            const modal = form.closest('[data-modal]');
            if (!modal?.id) return;

            const moduleCard = form.closest('[data-module-card]');
            sessionStorage.setItem('sg:restore-modal', modal.id);
            if (moduleCard?.id) {
                sessionStorage.setItem('sg:restore-module', moduleCard.id);
            } else {
                sessionStorage.removeItem('sg:restore-module');
            }
        });
    });

    const hasExplicitModal = Boolean(document.querySelector('[data-open-modal-on-load]'));
    const modalId = sessionStorage.getItem('sg:restore-modal');
    const moduleId = sessionStorage.getItem('sg:restore-module');
    sessionStorage.removeItem('sg:restore-modal');
    sessionStorage.removeItem('sg:restore-module');

    if (hasExplicitModal || !modalId) return;

    const modal = document.getElementById(modalId);
    if (!modal) return;

    if (moduleId) {
        const card = document.getElementById(moduleId);
        if (card) setModuleExpanded(card, true);
    }

    modal.hidden = false;
    requestAnimationFrame(() => {
        modal.classList.add('is-open');
        document.body.classList.add('sg-modal-open');

        if (moduleId) {
            window.setTimeout(() => {
                document.getElementById(moduleId)?.scrollIntoView({ block: 'start' });
            }, 120);
        }
    });
});
