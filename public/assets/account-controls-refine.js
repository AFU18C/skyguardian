(() => {
    const refine = (root = document) => {
        root.querySelectorAll('[data-account-card]').forEach((card) => {
            const trigger = card.querySelector('[data-account-toggle]');
            const chevron = trigger?.querySelector('.chevron');

            if (chevron) chevron.remove();
            if (trigger) {
                trigger.removeAttribute('aria-expanded');
                trigger.style.cursor = 'default';
                trigger.style.pointerEvents = 'none';
            }
        });
    };

    refine();

    const observer = new MutationObserver(() => refine());
    observer.observe(document.body, { childList: true, subtree: true });
})();