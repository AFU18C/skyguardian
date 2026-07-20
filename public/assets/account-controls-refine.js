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

    let attempts = 0;
    const timer = window.setInterval(() => {
        refine();
        attempts += 1;
        if (attempts >= 20 || document.querySelector('[data-account-card]')) {
            window.clearInterval(timer);
        }
    }, 100);
})();