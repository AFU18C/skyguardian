(() => {
    const refine = (root = document) => {
        root.querySelectorAll('[data-account-card]').forEach((card) => {
            const trigger = card.querySelector('[data-account-toggle]');
            const chevron = trigger?.querySelector('.chevron');
            const editButton = card.querySelector('[data-account-edit]');

            if (chevron) chevron.remove();
            if (trigger) {
                trigger.removeAttribute('aria-expanded');
                trigger.style.cursor = 'default';
                trigger.style.pointerEvents = 'none';
            }

            if (editButton && !editButton.dataset.toggleBound) {
                editButton.dataset.toggleBound = '1';
                editButton.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopImmediatePropagation();

                    const open = !card.classList.contains('open');
                    card.classList.toggle('open', open);

                    if (open) {
                        card.querySelector('input, select, textarea')?.focus();
                    }
                }, true);
            }
        });

        const sectionHeader = root.querySelector('main.content .section-header');
        if (sectionHeader) {
            const title = sectionHeader.querySelector('h2');
            const subtitle = sectionHeader.querySelector('p');
            if (title) title.textContent = 'Технические аккаунты';
            if (subtitle) subtitle.remove();
        }
    };

    refine();

    const observer = new MutationObserver(() => refine());
    observer.observe(document.body, { childList: true, subtree: true });
})();