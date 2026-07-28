const onSiteSettingsReady = (callback) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
        callback();
    }
};

onSiteSettingsReady(() => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    document.querySelectorAll('form[id^="site-menu-update-"]').forEach((form) => {
        if (!form.querySelector('[name="_token"]')) {
            const token = document.createElement('input');
            token.type = 'hidden';
            token.name = '_token';
            token.value = csrfToken;
            form.append(token);
        }
        if (!form.querySelector('[name="_method"]')) {
            const method = document.createElement('input');
            method.type = 'hidden';
            method.name = '_method';
            method.value = 'PUT';
            form.append(method);
        }
    });

    const typeSelect = document.querySelector('#new-menu-type');
    const pageSelect = document.querySelector('#new-menu-page');
    const urlInput = document.querySelector('#new-menu-url');

    const syncMenuType = () => {
        if (!typeSelect || !pageSelect || !urlInput) return;
        const isPage = typeSelect.value === 'page';
        pageSelect.disabled = !isPage;
        pageSelect.required = isPage;
        urlInput.disabled = isPage;
        urlInput.required = !isPage;
    };

    typeSelect?.addEventListener('change', syncMenuType);
    syncMenuType();
});
