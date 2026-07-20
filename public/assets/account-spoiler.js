(() => {
    const enhanceCard = (card) => {
        if (!(card instanceof HTMLElement) || card.dataset.spoilerReady === '1') return;

        const header = card.querySelector('.accordion-header');
        const trigger = card.querySelector('.accordion-trigger');
        const panel = card.querySelector('.accordion-panel');
        if (!header || !trigger || !panel) return;

        card.dataset.spoilerReady = '1';
        panel.style.removeProperty('display');

        const isNew = Boolean(card.closest('[data-new-account]'));
        card.classList.toggle('open', isNew);
        trigger.setAttribute('role', 'button');
        trigger.setAttribute('tabindex', '0');
        trigger.setAttribute('aria-expanded', isNew ? 'true' : 'false');

        if (!trigger.querySelector('.chevron')) {
            const chevron = document.createElement('span');
            chevron.className = 'chevron';
            chevron.setAttribute('aria-hidden', 'true');
            chevron.textContent = '⌄';
            trigger.appendChild(chevron);
        }

        const toggle = () => {
            const next = !card.classList.contains('open');
            card.classList.toggle('open', next);
            trigger.setAttribute('aria-expanded', next ? 'true' : 'false');
        };

        trigger.addEventListener('click', toggle);
        trigger.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggle();
            }
        });
    };

    const enhanceAll = () => {
        document.querySelectorAll('[data-account-card]').forEach(enhanceCard);
    };

    enhanceAll();

    const observer = new MutationObserver(enhanceAll);
    observer.observe(document.body, { childList: true, subtree: true });
})();
