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
            trigger?.querySelector('.chevron')?.remove();

            if (trigger) {
                trigger.style.pointerEvents = 'none';
                trigger.style.cursor = 'default';
            }

            edit?.addEventListener('click', () => {
                const next = !card.classList.contains('open');
                card.classList.toggle('open', next);
                trigger?.setAttribute('aria-expanded', next ? 'true' : 'false');
                if (next) card.querySelector('input, select, textarea')?.focus();
            });
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

    const channelMatch = window.location.pathname.match(/^\/(news|alerts)\/channels\/?$/);
    if (channelMatch) {
        const section = channelMatch[1];
        const accountSelects = [...document.querySelectorAll('select[name="account_id"]')];

        fetch(`/settings-account.php?section=${encodeURIComponent(section)}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
            .then((response) => response.json().then((data) => ({ response, data })))
            .then(({ response, data }) => {
                if (!response.ok || data.ok !== true || !Array.isArray(data.accounts)) {
                    throw new Error(data.message || 'Не удалось загрузить технические аккаунты.');
                }

                const accounts = data.accounts.filter((account) => account && account.active !== false);
                accountSelects.forEach((select) => {
                    const selectedId = select.value;
                    select.replaceChildren(new Option('Выберите аккаунт', ''));
                    accounts.forEach((account) => {
                        const option = new Option(String(account.name || account.id || 'Технический аккаунт'), String(account.id || ''));
                        option.selected = String(account.id || '') === selectedId;
                        select.add(option);
                    });
                });

                if (accounts.length > 0) {
                    document.querySelectorAll('.notice.warning').forEach((notice) => {
                        if (notice.textContent?.includes('технический аккаунт')) notice.remove();
                    });
                }
            })
            .catch((error) => {
                console.error('Technical accounts loading failed:', error);
            });
    }

    if (/^\/(news|alerts)\/settings\/?$/.test(window.location.pathname)) {
        const assetVersion = Date.now().toString();

        const settingsScript = document.createElement('script');
        settingsScript.src = `/assets/settings-accounts-v2.js?v=${assetVersion}`;
        settingsScript.async = false;
        document.body.appendChild(settingsScript);

        const refineScript = document.createElement('script');
        refineScript.src = `/assets/account-controls-refine.js?v=${assetVersion}`;
        refineScript.async = false;
        document.body.appendChild(refineScript);
    }
})();