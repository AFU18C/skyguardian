(() => {
    const match = window.location.pathname.match(/^\/(news|alerts)\/settings\/?$/);
    if (!match) return;

    const section = match[1];
    const content = document.querySelector('main.content');
    if (!content) return;

    let csrf = '';
    let accounts = [];
    let pollTimer = null;
    let currentAccount = null;

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    })[char]);

    const request = async (body = null) => {
        const options = body ? {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams(body)
        } : {};
        const url = `/settings-account.php?section=${encodeURIComponent(section)}`;
        const response = await fetch(url, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.ok !== true) throw new Error(data.message || 'Не удалось выполнить действие.');
        return data;
    };

    const qrDialog = document.createElement('dialog');
    qrDialog.className = 'modal';
    qrDialog.style.padding = '0';
    qrDialog.style.width = 'min(94vw, 430px)';
    qrDialog.style.maxWidth = '94vw';
    qrDialog.style.overflow = 'hidden';
    qrDialog.innerHTML = `<div class="modal-body" style="width:100%;max-width:100%;box-sizing:border-box;overflow:hidden">
        <h2 style="margin-top:0">Подключение Telegram</h2>
        <p data-qr-message>Подготовка QR-кода…</p>
        <div data-qr-code style="display:grid;place-items:center;width:100%;max-width:100%;min-height:220px;padding:12px;box-sizing:border-box;overflow:hidden;background:#fff;border-radius:14px"></div>
        <form data-qr-2fa class="hidden" style="margin-top:16px">
            <label>Пароль двухэтапной аутентификации<input type="password" autocomplete="current-password" required></label>
            <button class="button primary full" type="submit" style="margin-top:12px">Подтвердить пароль</button>
        </form>
        <div class="modal-actions"><button class="button ghost" type="button" data-qr-close>Закрыть</button></div>
    </div>`;
    document.body.appendChild(qrDialog);

    const qrMessage = qrDialog.querySelector('[data-qr-message]');
    const qrCode = qrDialog.querySelector('[data-qr-code]');
    const qr2fa = qrDialog.querySelector('[data-qr-2fa]');
    const qrPassword = qr2fa.querySelector('input');

    const fitQrSvg = () => {
        const svg = qrCode.querySelector('svg');
        if (!svg) return;
        svg.removeAttribute('width');
        svg.removeAttribute('height');
        svg.style.display = 'block';
        svg.style.width = '100%';
        svg.style.height = 'auto';
        svg.style.maxWidth = '340px';
        svg.style.maxHeight = '62vh';
    };

    const stopPolling = () => {
        if (pollTimer) window.clearTimeout(pollTimer);
        pollTimer = null;
    };
    qrDialog.addEventListener('close', stopPolling);
    qrDialog.querySelector('[data-qr-close]').addEventListener('click', () => qrDialog.close());

    const qrRequest = async (action = 'status', password = '') => {
        if (!currentAccount) return;
        const response = await fetch('/telegram-qr.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({
                csrf,
                section,
                action,
                account_id: currentAccount.id,
                api_id: currentAccount.api_id,
                api_hash: currentAccount.api_hash,
                password
            })
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.ok !== true) throw new Error(data.message || 'Ошибка подключения Telegram.');
        return data;
    };

    const showQrState = async (action = 'status', password = '') => {
        stopPolling();
        try {
            const data = await qrRequest(action, password);
            if (data.logged_in) {
                qrMessage.textContent = 'Telegram-аккаунт успешно подключён.';
                qrCode.innerHTML = '<div style="color:#15803d;font-size:64px">✓</div>';
                qr2fa.classList.add('hidden');
                window.setTimeout(load, 700);
                return;
            }
            if (data.needs_2fa) {
                qrMessage.textContent = 'Введите пароль двухэтапной аутентификации Telegram.';
                qrCode.innerHTML = '<div style="color:#334155">QR-код отсканирован</div>';
                qr2fa.classList.remove('hidden');
                qrPassword.focus();
                return;
            }
            qr2fa.classList.add('hidden');
            qrMessage.textContent = 'Telegram → Настройки → Устройства → Подключить устройство.';
            qrCode.innerHTML = data.svg || '<div>Ожидание QR-кода…</div>';
            fitQrSvg();
            pollTimer = window.setTimeout(() => showQrState('status'), 1800);
        } catch (error) {
            qrMessage.textContent = error.message || 'Не удалось подключиться к Telegram.';
            qrCode.innerHTML = '<div style="color:#b91c1c;font-size:48px">!</div>';
        }
    };

    qr2fa.addEventListener('submit', (event) => {
        event.preventDefault();
        showQrState('2fa', qrPassword.value);
    });

    const accountCard = (account = {}, isNew = false) => {
        const connected = account.connected === true;
        return `<article class="accordion-card open" data-account-card>
            <div class="accordion-header"><div class="accordion-trigger"><span><strong>${escapeHtml(account.name || 'Новый технический аккаунт')}</strong><small>${connected ? 'Telegram подключён' : 'Telegram не подключён'}</small></span></div></div>
            <div class="accordion-panel" style="display:block">
                <form class="form-grid" data-account-form>
                    <input type="hidden" name="id" value="${escapeHtml(account.id || '')}">
                    <div class="grid-2">
                        <label>Название <span class="required">*</span><input required name="name" value="${escapeHtml(account.name || '')}"></label>
                        <label>API ID <span class="required">*</span><input required inputmode="numeric" name="api_id" value="${escapeHtml(account.api_id || '')}"></label>
                        <label>API Hash <span class="required">*</span><input required type="password" name="api_hash" value="${escapeHtml(account.api_hash || '')}"></label>
                    </div>
                    <div class="qr-box"><div><strong>Telegram-сессия</strong><p>${connected ? 'Аккаунт подключён.' : 'Сохраните аккаунт и подключите его по QR-коду.'}</p></div><button class="button ghost" type="button" data-connect ${isNew ? 'disabled' : ''}>Подключить по QR-коду</button></div>
                    <div class="form-actions"><button class="button primary" type="submit">Сохранить</button>${isNew ? '' : '<button class="button danger" type="button" data-delete>Удалить</button>'}</div>
                </form>
            </div>
        </article>`;
    };

    const render = () => {
        const title = section === 'news' ? 'Новости' : 'Воздушная тревога';
        content.innerHTML = `<div class="section-header"><div><h2>Технические аккаунты Telegram</h2><p>Отдельные аккаунты для раздела «${title}»</p></div><button class="button primary" type="button" data-add-account>Добавить аккаунт</button></div>
            <div class="accordion-list" data-account-list>${accounts.map((account) => accountCard(account)).join('') || '<div class="empty-state"><div>＋</div><h3>Аккаунтов пока нет</h3><p>Добавьте технический Telegram-аккаунт.</p></div>'}</div>`;

        content.querySelector('[data-add-account]').addEventListener('click', () => {
            const list = content.querySelector('[data-account-list]');
            if (list.querySelector('[data-new-account]')) return;
            list.insertAdjacentHTML('afterbegin', `<div data-new-account>${accountCard({}, true)}</div>`);
            bindForms();
        });
        bindForms();
    };

    const bindForms = () => {
        content.querySelectorAll('[data-account-form]').forEach((form) => {
            if (form.dataset.bound) return;
            form.dataset.bound = '1';
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const fields = new FormData(form);
                try {
                    await request({
                        csrf, section, action: 'save',
                        id: fields.get('id') || '', name: fields.get('name') || '',
                        api_id: fields.get('api_id') || '', api_hash: fields.get('api_hash') || '', active: '1'
                    });
                    await load();
                } catch (error) { window.alert(error.message); }
            });
            form.querySelector('[data-delete]')?.addEventListener('click', async () => {
                if (!window.confirm('Удалить технический аккаунт?')) return;
                try {
                    await request({ csrf, section, action: 'delete', id: form.querySelector('[name="id"]').value });
                    await load();
                } catch (error) { window.alert(error.message); }
            });
            form.querySelector('[data-connect]')?.addEventListener('click', () => {
                const id = form.querySelector('[name="id"]').value;
                const account = accounts.find((item) => item.id === id);
                if (!account) return;
                currentAccount = account;
                qrMessage.textContent = 'Подготовка QR-кода…';
                qrCode.innerHTML = '<div>Загрузка…</div>';
                qr2fa.classList.add('hidden');
                qrDialog.showModal();
                showQrState('start');
            });
        });
    };

    async function load() {
        stopPolling();
        const data = await request();
        csrf = data.csrf;
        accounts = Array.isArray(data.accounts) ? data.accounts : [];
        render();
    }

    load().catch((error) => {
        content.innerHTML = `<div class="notice error">${escapeHtml(error.message)}</div>`;
    });
})();