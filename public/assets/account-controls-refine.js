(() => {
    const refine = () => {
        document.querySelectorAll('[data-account-card]').forEach((card) => {
            const trigger = card.querySelector('[data-account-toggle]');
            const chevron = trigger?.querySelector('.chevron');

            chevron?.remove();

            if (trigger && trigger.dataset.refined !== '1') {
                trigger.dataset.refined = '1';
                trigger.removeAttribute('aria-expanded');
                trigger.style.cursor = 'default';
                trigger.style.pointerEvents = 'none';
            }
        });

        const sectionHeader = document.querySelector('main.content .section-header');
        if (sectionHeader && sectionHeader.dataset.refined !== '1') {
            sectionHeader.dataset.refined = '1';
            const title = sectionHeader.querySelector('h2');
            const subtitle = sectionHeader.querySelector('p');
            if (title) title.textContent = 'Технические аккаунты';
            subtitle?.remove();
        }
    };

    document.addEventListener('click', (event) => {
        const editButton = event.target.closest('[data-account-edit]');
        if (!editButton) return;

        const card = editButton.closest('[data-account-card]');
        if (!card) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        const willOpen = !card.classList.contains('open');
        card.classList.toggle('open', willOpen);

        if (willOpen) {
            card.querySelector('input, select, textarea')?.focus();
        }
    }, true);

    refine();

    let attempts = 0;
    const timer = window.setInterval(() => {
        refine();
        attempts += 1;
        if (attempts >= 30) window.clearInterval(timer);
    }, 100);
})();