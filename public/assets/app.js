(() => {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    document.getElementById('menuToggle')?.addEventListener('click', () => {
        sidebar?.classList.toggle('open');
        overlay?.classList.toggle('open');
    });
    overlay?.addEventListener('click', () => {
        sidebar?.classList.remove('open');
        overlay?.classList.remove('open');
    });

    const bindAccordions = (root = document) => {
        root.querySelectorAll('[data-accordion]').forEach((card) => {
            if (card.dataset.accordionBound) return;
            card.dataset.accordionBound = '1';
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
    };
    bindAccordions();

    const reveal = (buttonId, wrapperId) => {
        document.getElementById(buttonId)?.addEventListener('click', () => {
            const wrapper = document.getElementById(wrapperId);
            wrapper?.classList.remove('hidden');
            wrapper?.querySelector('input, select, textarea')?.focus();
            bindAccordions(wrapper || document);
        });
    };
    reveal('showNewChannel', 'newChannelWrap');
    reveal('showNewAccount', 'newAccountWrap');

    document.querySelectorAll('[data-editor]').forEach((editor) => {
        const block = editor.closest('.editor-block');
        const hidden = block?.querySelector('[data-editor-input]');
        const sync = () => { if (hidden) hidden.value = editor.innerHTML.trim(); };
        editor.addEventListener('input', sync);
        editor.closest('form')?.addEventListener('submit', sync);
        block?.querySelectorAll('[data-command]').forEach((button) => {
            button.addEventListener('click', () => {
                const command = button.dataset.command;
                editor.focus();
                if (command === 'createLink') {
                    const url = window.prompt('Введите ссылку:');
                    if (url) document.execCommand('createLink', false, url);
                } else if (command) {
                    document.execCommand(command, false);
                }
                sync();
            });
        });
        sync();
    });

    const token = document.querySelector('input[name="csrf"]')?.value || '';
    const postToggle = async (url, id, active, checkbox) => {
        checkbox.disabled = true;
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ csrf: token, id, active: active ? '1' : '0' })
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
    document.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => dialog?.close()));
    confirmDelete?.addEventListener('click', () => pendingForm?.submit());
    dialog?.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });

    document.querySelectorAll('[data-autohide]').forEach((notice) => window.setTimeout(() => notice.remove(), 4500));

    if (/^\/(news|alerts)\/settings\/?$/.test(window.location.pathname)) {
        const script = document.createElement('script');
        script.src = '/assets/settings-accounts.js?v=1';
        script.defer = true;
        document.body.appendChild(script);
    }
})();
