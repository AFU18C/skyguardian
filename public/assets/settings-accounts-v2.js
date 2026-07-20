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
        const response = await fetch(`/settings-account.php?section=${encodeURIComponent(section)}`, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.ok !== true) throw new Error(data.message || 'Не удалось выполнить действие.');
        return data;
    };

    const qrDialog = document.createElement('dialog');
    qrDialog.setAttribute('aria-labelledby', 'telegramQrTitle');
    Object.assign(qrDialog.style, {
        position: 'fixed', inset: '0', width: '100vw', height: '100dvh', maxWidth: 'none', maxHeight: 'none',
        margin: '0', padding: '12px', border: '0', background: 'transparent', overflow: 'hidden', boxSizing: 'border-box'
    });
    qrDialog.innerHTML = `
        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;overflow:hidden;min-width:0">
            <section style="width:calc(100vw - 24px);max-width:360px;max-height:calc(100dvh - 24px);min-width:0;padding:20px;box-sizing:border-box;overflow-y:auto;overflow-x:hidden;border:1px solid #223652;border-radius:18px;background:#0c1728;color:#eef5ff;box-shadow:0 18px 50px rgba(0,0,0,.35)">
                <h2 id="telegramQrTitle" style="margin:0 0 12px;font-size:clamp(24px,7vw,32px);line-height:1.15;overflow-wrap:anywhere">Подключение Telegram</h2>
                <p data-qr-message style="margin:0 0 16px;color:#8fa3bd;line-height:1.45;overflow-wrap:anywhere">Подготовка QR-кода…</p>
                <div data-qr-code style="display:flex;align-items:center;justify-content:center;width:240px;height:240px;max-width:100%;margin:0 auto;padding:8px;box-sizing:border-box;overflow:hidden;background:#fff;border-radius:14px;flex:0 0 auto;min-width:0"></div>
                <form data-qr-2fa class="hidden" style="margin-top:16px">
                    <label>Пароль двухэтапной аутентификации<input type="password" autocomplete="current-password" required></label>
                    <button class="button primary full" type="submit" style="margin-top:12px">Подтвердить пароль</button>
                </form>
                <div style="display:flex;justify-content:flex-end;margin-top:18px"><button class="button ghost" type="button" data-qr-close>Закрыть</button></div>
            </section>
        </div>`;
    document.body.appendChild(qrDialog);

    const qrMessage = qrDialog.querySelector('[data-qr-message]');
    const qrCode = qrDialog.querySelector('[data-qr-code]');
    const qr2fa = qrDialog.querySelector('[data-qr-2fa]');
    const qrPassword = qr2fa.querySelector('input');

    const renderQrImage = (svgMarkup) => {
        const size = 224;
        const parser = new DOMParser();
        const documentSvg = parser.parseFromString(svgMarkup, 'image/svg+xml');
        const svg = documentSvg.documentElement;
        const width = Number.parseFloat(svg.getAttribute('width') || '400') || 400;
        const height = Number.parseFloat(svg.getAttribute('height') || String(width)) || width;
        if (!svg.getAttribute('viewBox')) svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        svg.setAttribute('width', String(size));
        svg.setAttribute('height', String(size));
        svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');

        const source = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(new XMLSerializer().serializeToString(svg))}`;
        const image = new Image();
        image.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            Object.assign(canvas.style, { display: 'block', width: `${size}px`, height: `${size}px`, maxWidth: '100%', flex: '0 0 auto' });
            const context = canvas.getContext('2d');
            if (!context) return;
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, size, size);
            context.drawImage(image, 0, 0, size, size);
            qrCode.replaceChildren(canvas);
        };
        image.onerror = () => { qrCode.innerHTML = '<div style="color:#b91c1c;text-align:center">Не удалось отобразить QR-код</div>'; };
        image.src = source;
    };

    const lockPage = () => {
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
        document.documentElement.scrollLeft = 0;
        document.body.scrollLeft = 0;
        qrDialog.scrollLeft = 0;
    };
    const unlockPage = () => {
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
    };
    const stopPolling = () => {
        if (pollTimer) window.clearTimeout(pollTimer);
        pollTimer = null;
    };

    qrDialog.addEventListener('close', () => { stopPolling(); unlockPage(); });
    qrDialog.querySelector('[data-qr-close]').addEventListener('click', () => qrDialog.close());

    const qrRequest = async (action = 'status', password = '') => {
        if (!currentAccount) return null;
        const response = await fetch('/telegram-qr.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({
                csrf, section, action,
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
            if (!data) return;
            if (data.logged_in) {
                qrMessage.textContent = 'Telegram-аккаунт успешно подключён.';
                qrCode.innerHTML = '<div style="color:#15803d;font-size:64px">✓</div>';
                qr2fa.classList.add('hidden');
                window.setTimeout(load, 700);
                return;
            }
            if (data.needs_2fa) {
                qrMessage.textContent = 'Введите пароль двухэтапной аутентификации Telegram.';
                qrCode.innerHTML = '<div style="color:#334155;text-align:center">QR-код отсканирован</div>';
                qr2fa.classList.remove('hidden');
                qrPassword.focus();
                return;
            }
            qr2fa.classList.add('hidden');
            qrMessage.textContent = 'Telegram → Настройки → Устройства → Подключить устройство.';
            if (data.svg) renderQrImage(data.svg);
            else qrCode.innerHTML = '<div style="color:#334155">Ожидание QR-кода…</div>';
            pollTimer = window.setTimeout(() => showQrState('status'), 1800);
        } catch (error) {
            qrMessage.textContent = error instanceof Error ? error.message : 'Не удалось подключиться к Telegram.';
            qrCode.innerHTML = '<div style="color:#b91c1c;font-size:48px">!</div>';
        }
    };

    qr2fa.addEventListener('submit', (event) => {
        event.preventDefault();
        showQrState('2fa', qrPassword.value);
    });

    const accountCard = (account = {}, isNew = false) => {
        const connected = account.connected === true;
        const active = account.active !== false;
        const hasError = Boolean(account.error);
        const statusColor = hasError ? '#ef4444' : (connected ? '#22c55e' : '#64748b');
        const statusTitle = hasError ? 'Ошибка подключения' : (connected ? 'Telegram подключён' : 'Telegram не подключён');
        const openClass = isNew ? ' open' : '';
        return `<article class="accordion-card${openClass}" data-account-card data-account-id="${escapeHtml(account.id || '')}">
            <div class="accordion-header">
                <button type="button" class="accordion-trigger" data-account-toggle aria-expanded="${isNew ? 'true' : 'false'}">
                    <span><strong>${escapeHtml(account.name || 'Новый технический аккаунт')}</strong><small><span aria-hidden="true" style="display:inline-block;width:10px;height:10px;margin-right:7px;border-radius:50%;background:${statusColor}"></span>${statusTitle}</small></span>
                    <span class="chevron">⌄</span>
                </button>
                ${isNew ? '' : `<label class="switch" title="Включить или выключить аккаунт"><input type="checkbox" data-account-active ${active ? 'checked' : ''}><span></span></label>
                <button type="button" class="icon-button edit-trigger" data-account-edit aria-label="Редактировать аккаунт">✎</button>`}
            </div>
            <div class="accordion-panel">
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

    const setCardOpen = (card, open) => {
        card.classList.toggle('open', open);
        card.querySelector('[data-account-toggle]')?.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    const bindForms = () => {
        content.querySelectorAll('[data-account-card]').forEach((card) => {
            if (card.dataset.bound) return;
            card.dataset.bound = '1';

            card.querySelector('[data-account-toggle]')?.addEventListener('click', () => setCardOpen(card, !card.classList.contains('open')));
            card.querySelector('[data-account-edit]')?.addEventListener('click', () => {
                setCardOpen(card, true);
                card.querySelector('input, select, textarea')?.focus();
            });

            const activeToggle = card.querySelector('[data-account-active]');
            activeToggle?.addEventListener('change', async () => {
                const id = card.dataset.accountId || '';
                activeToggle.disabled = true;
                try {
                    await request({ csrf, section, action: 'toggle', id, active: activeToggle.checked ? '1' : '0' });
                    const account = accounts.find((item) => item.id === id);
                    if (account) account.active = activeToggle.checked;
                } catch (error) {
                    activeToggle.checked = !activeToggle.checked;
                    window.alert(error.message || 'Не удалось изменить состояние аккаунта.');
                } finally {
                    activeToggle.disabled = false;
                }
            });

            const form = card.querySelector('[data-account-form]');
            if (!form) return;
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const fields = new FormData(form);
                const id = fields.get('id') || '';
                const account = accounts.find((item) => item.id === id);
                try {
                    await request({
                        csrf, section, action: 'save', id,
                        name: fields.get('name') || '', api_id: fields.get('api_id') || '',
                        api_hash: fields.get('api_hash') || '', active: account?.active === false ? '0' : '1'
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
                qrCode.innerHTML = '<div style="color:#334155">Загрузка…</div>';
                qr2fa.classList.add('hidden');
                lockPage();
                qrDialog.showModal();
                requestAnimationFrame(() => {
                    window.scrollTo({ left: 0, top: window.scrollY, behavior: 'instant' });
                    qrDialog.scrollLeft = 0;
                });
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