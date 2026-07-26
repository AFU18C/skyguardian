const onReady = (callback) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
        callback();
    }
};

onReady(() => {
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
            if (!toggle || !checkbox) return;
            toggle.hidden = !checkbox.checked;
            if (!checkbox.checked) setModuleExpanded(card, false);
        };

        toggle?.addEventListener('click', () => {
            if (!checkbox?.checked || !panel) return;
            setModuleExpanded(card, panel.hidden);
        });
        checkbox?.addEventListener('change', syncAvailability);
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
