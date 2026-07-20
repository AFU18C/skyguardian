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

    const qrDialog = document.createElement('dialog');
    qrDialog.className = 'modal';
    qrDialog.setAttribute('aria-labelledby', 'telegramQrTitle');
    qrDialog.innerHTML = `
        <div class="modal-body" style="width:min(92vw,430px)">
            <h2 id="telegramQrTitle">Подключение Telegram</h2>
            <p id="telegramQrMessage">Подготовка QR-кода…</p>
            <div id="telegramQrCode" style="display:grid;place-items:center;min-height:280px;padding:14px;background:#fff;border-radius:14px"></div>
            <form id="telegramTwoFactor" class="hidden" style="margin-top:16px">
                <label>Пароль двухэтапной аутентификации
                    <input id="telegramTwoFactorPassword" type="password" autocomplete="current-password" required>
                </label>
                <button class="button primary full" type="submit" style="margin-top:12px">Подтвердить пароль</button>
            </form>
            <div class="modal-actions"><button class="button ghost" type="button" id="telegramQrClose">Закрыть</button></div>
        </div>`;
    document.body.appendChild(qrDialog);

    const qrMessage = qrDialog.querySelector('#telegramQrMessage');
    const qrCode = qrDialog.querySelector('#telegramQrCode');
    const twoFactorForm = qrDialog.querySelector('#telegramTwoFactor');
    const twoFactorPassword = qrDialog.querySelector('#telegramTwoFactorPassword');
    qrDialog.querySelector('#telegramQrClose')?.addEventListener('click', () => qrDialog.close());

    let qrTimer = null;
    let qrAccount = null;

    const stopQrPolling = () => {
        if (qrTimer) window.clearTimeout(qrTimer);
        qrTimer = null;
    };

    qrDialog.addEventListener('close', stopQrPolling);

    const requestQrState = async (action = 'status', password = '') => {
        if (!qrAccount) return;
        const body = new URLSearchParams({
            csrf: token,
            action,
            account_id: qrAccount.id,
            api_id: qrAccount.apiId,
            api_hash: qrAccount.apiHash,
            password
        });
        const response = await fetch('/telegram-qr.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.ok !== true) throw new Error(data.message || 'Ошибка подключения Telegram.');
        return data;
    };

    const renderQrState = async (action = 'status', password = '') => {
        stopQrPolling();
        try {
            const data = await requestQrState(action, password);
            if (data.logged_in) {
                qrMessage.textContent = 'Telegram-аккаунт успешно подключён.';
                qrCode.innerHTML = '<div style="color:#15803d;font-size:64px">✓</div>';
                twoFactorForm.classList.add('hidden');
                window.setTimeout(() => window.location.reload(), 900);
                return;
            }
            if (data.needs_2fa) {
                qrMessage.textContent = 'Введите пароль двухэтапной аутентификации Telegram.';
                qrCode.innerHTML = '<div style="color:#334155;text-align:center">QR-код отсканирован</div>';
                twoFactorForm.classList.remove('hidden');
                twoFactorPassword.focus();
                return;
            }
            twoFactorForm.classList.add('hidden');
            qrMessage.textContent = 'Откройте Telegram → Настройки → Устройства → Подключить устройство и отсканируйте QR-код.';
            qrCode.innerHTML = data.svg || '<div style="color:#334155">Ожидание QR-кода…</div>';
            qrTimer = window.setTimeout(() => renderQrState('status'), 1800);
        } catch (error) {
            qrMessage.textContent = error instanceof Error ? error.message : 'Не удалось подключиться к Telegram.';
            qrCode.innerHTML = '<div style="color:#b91c1c;font-size:48px">!</div>';
        }
    };

    twoFactorForm.addEventListener('submit', (event) => {
        event.preventDefault();
        renderQrState('2fa', twoFactorPassword.value);
    });

    document.querySelectorAll('.qr-box').forEach((box) => {
        const button = box.querySelector('button');
        const form = box.closest('form');
        const id = form?.querySelector('input[name="id"]')?.value.trim() || '';
        if (!button || !form) return;
        button.disabled = false;
        button.textContent = 'Подключить по QR-коду';
        button.addEventListener('click', () => {
            const apiId = form.querySelector('input[name="api_id"]')?.value.trim() || '';
            const apiHash = form.querySelector('input[name="api_hash"]')?.value.trim() || '';
            if (!id) {
                window.alert('Сначала сохраните технический аккаунт, затем подключите его по QR-коду.');
                return;
            }
            if (!apiId || !apiHash) {
                window.alert('Заполните API ID и API Hash.');
                return;
            }
            qrAccount = { id, apiId, apiHash };
            qrMessage.textContent = 'Подготовка QR-кода…';
            qrCode.innerHTML = '<div style="color:#334155">Загрузка…</div>';
            twoFactorForm.classList.add('hidden');
            qrDialog.showModal();
            renderQrState('start');
        });
    });

    document.querySelectorAll('[data-autohide]').forEach((notice) => {
        window.setTimeout(() => notice.remove(), 4500);
    });
})();
