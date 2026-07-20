(() => {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const menuToggle = document.getElementById('menuToggle');

    const closeSidebar = () => {
        sidebar?.classList.remove('open');
        overlay?.classList.remove('open');
    };

    menuToggle?.addEventListener('click', () => {
        sidebar?.classList.toggle('open');
        overlay?.classList.toggle('open');
    });
    overlay?.addEventListener('click', closeSidebar);

    document.querySelectorAll('[data-accordion]').forEach((card) => {
        const trigger = card.querySelector('.accordion-trigger');
        const edit = card.querySelector('.edit-trigger');
        const open = () => {
            card.classList.add('open');
            trigger?.setAttribute('aria-expanded', 'true');
        };
        trigger?.addEventListener('click', () => {
            const next = !card.classList.contains('open');
            card.classList.toggle('open', next);
            trigger.setAttribute('aria-expanded', next ? 'true' : 'false');
        });
        edit?.addEventListener('click', open);
    });

    const reveal = (buttonId, wrapperId) => {
        document.getElementById(buttonId)?.addEventListener('click', () => {
            const wrapper = document.getElementById(wrapperId);
            wrapper?.classList.remove('hidden');
            wrapper?.querySelector('input, select, textarea')?.focus();
        });
    };
    reveal('showNewChannel', 'newChannelWrap');
    reveal('showNewAccount', 'newAccountWrap');

    document.querySelectorAll('[data-editor]').forEach((editor) => {
        const block = editor.closest('.editor-block');
        const hidden = block?.querySelector('[data-editor-input]');
        const sync = () => {
            if (hidden) hidden.value = editor.innerHTML.trim();
        };
        editor.addEventListener('input', sync);
        editor.closest('form')?.addEventListener('submit', sync);
        sync();
    });

    const token = document.querySelector('input[name="csrf"]')?.value || '';
    const postToggle = async (url, id, active, checkbox) => {
        checkbox.disabled = true;
        try {
            const body = new URLSearchParams({ csrf: token, id, active: active ? '1' : '0' });
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body
            });
            if (!response.ok) throw new Error('toggle failed');
            const form = checkbox.closest('[data-accordion]')?.querySelector('form:not([hidden])');
            const hiddenActive = form?.querySelector('input[name="active"]');
            if (hiddenActive) hiddenActive.value = active ? '1' : '0';
        } catch {
            checkbox.checked = !active;
            window.alert('Не удалось изменить состояние. Повторите попытку.');
        } finally {
            checkbox.disabled = false;
        }
    };

    document.querySelectorAll('.channel-toggle').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            const section = checkbox.dataset.section;
            const id = checkbox.dataset.id;
            if (section && id && id !== 'new') postToggle(`/${section}/channels/toggle`, id, checkbox.checked, checkbox);
        });
    });

    document.querySelectorAll('.account-toggle').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            const id = checkbox.dataset.id;
            if (id && id !== 'new') postToggle('/group/accounts/toggle', id, checkbox.checked, checkbox);
        });
    });

    const dialog = document.getElementById('confirmDialog');
    const confirmDelete = document.getElementById('confirmDelete');
    let pendingForm = null;

    document.querySelectorAll('[data-delete-form]').forEach((button) => {
        button.addEventListener('click', () => {
            pendingForm = document.getElementById(button.dataset.deleteForm || '');
            dialog?.showModal();
        });
    });
    document.querySelectorAll('[data-close-dialog]').forEach((button) => {
        button.addEventListener('click', () => dialog?.close());
    });
    confirmDelete?.addEventListener('click', () => pendingForm?.submit());
    dialog?.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    document.querySelectorAll('[data-autohide]').forEach((notice) => {
        window.setTimeout(() => notice.remove(), 4500);
    });

    const qrContainer = document.getElementById('telegramQr');
    const accountId = window.SG_TELEGRAM_ACCOUNT;
    if (qrContainer && accountId) {
        let stopped = false;
        const loadQr = async (wait = false) => {
            if (stopped) return;
            try {
                const response = await fetch(`/group/accounts/${encodeURIComponent(accountId)}/telegram/qr${wait ? '?wait=1' : ''}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                const result = await response.json();
                if (!response.ok || result.error) throw new Error(result.error || 'Не удалось получить QR-код.');
                if (result.logged_in) {
                    stopped = true;
                    qrContainer.innerHTML = '<div class="notice success"><strong>Telegram подключён.</strong><br>Можно вернуться к настройке канала.</div>';
                    window.setTimeout(() => window.location.assign('/group'), 1200);
                    return;
                }
                if (result.svg) {
                    qrContainer.innerHTML = result.svg;
                }
                window.setTimeout(() => loadQr(true), 300);
            } catch (error) {
                qrContainer.innerHTML = `<div class="notice error">${String(error.message || error)}</div>`;
                window.setTimeout(() => loadQr(false), 3000);
            }
        };
        loadQr(false);
    }
})();
