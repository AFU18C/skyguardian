const initCollapsibleCards = () => {
    document.querySelectorAll('[data-collapsible-card]').forEach((card) => {
        const toggle = card.querySelector('[data-card-toggle]');
        const details = card.querySelector('[data-card-details]');

        if (!toggle || !details) return;

        const setExpanded = (expanded) => {
            card.classList.toggle('is-expanded', expanded);
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            details.hidden = !expanded;

            const baseLabel = toggle.getAttribute('aria-label') || 'Карточка';
            const cleanLabel = baseLabel
                .replace(/^Развернуть\s+/i, '')
                .replace(/^Свернуть\s+/i, '');
            toggle.setAttribute('aria-label', `${expanded ? 'Свернуть' : 'Развернуть'} ${cleanLabel}`);
        };

        setExpanded(toggle.getAttribute('aria-expanded') === 'true');

        toggle.addEventListener('click', () => {
            setExpanded(toggle.getAttribute('aria-expanded') !== 'true');
        });
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCollapsibleCards, { once: true });
} else {
    initCollapsibleCards();
}
