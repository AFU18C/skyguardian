import QRCode from 'qrcode';

const ready = (callback) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
        callback();
    }
};

ready(() => {
    const body = document.body;
    const sidebar = document.querySelector('[data-sidebar]');
    const overlay = document.querySelector('[data-sidebar-overlay]');

    const closeSidebar = () => {
        sidebar?.classList.remove('is-open');
        overlay?.classList.remove('is-visible');
    };

    document.querySelector('[data-sidebar-open]')?.addEventListener('click', () => {
        sidebar?.classList.add('is-open');
        overlay?.classList.add('is-visible');
    });
    document.querySelector('[data-sidebar-close]')?.addEventListener('click', closeSidebar);
    overlay?.addEventListener('click', closeSidebar);

    const openModal = (id, scrollSelector = null) => {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.hidden = false;
        requestAnimationFrame(() => {
            modal.classList.add('is-open');
            if (scrollSelector) {
                window.setTimeout(() => {
                    const target = modal.querySelector(scrollSelector);
                    target?.scrollIntoView({ block: 'start', behavior: 'smooth' });
                    target?.querySelector('[autofocus], input:not([type="hidden"]), select, textarea, button')?.focus();
                }, 120);
            } else {
                modal.querySelector('input:not([type="hidden"]), select, textarea, button')?.focus();
            }
        });
        body.classList.add('sg-modal-open');
    };

    const formIsDirty = (modal) => Boolean(modal?.querySelector('[data-dirty-form].is-dirty'));

    const closeModal = (modal, force = false) => {
        if (!modal) return;
        if (!force && formIsDirty(modal) && !window.confirm('Закрыть форму без сохранения изменений?')) return;
        modal.classList.remove('is-open');
        window.setTimeout(() => {
            modal.hidden = true;
            if (!document.querySelector('.sg-modal.is-open')) body.classList.remove('sg-modal-open');
        }, 180);
    };

    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-modal-open]');
        if (opener) {
            event.preventDefault();
            openModal(opener.dataset.modalOpen);
            return;
        }

        const closer = event.target.closest('[data-modal-close]');
        if (closer) {
            event.preventDefault();
            closeModal(closer.closest('[data-modal]'));
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeModal(document.querySelector('.sg-modal.is-open'));
    });

    document.querySelectorAll('[data-dirty-form]').forEach((form) => {
        form.addEventListener('input', () => form.classList.add('is-dirty'));
        form.addEventListener('change', () => form.classList.add('is-dirty'));
        form.addEventListener('submit', () => form.classList.remove('is-dirty'));
    });

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm || 'Подтвердить действие?')) event.preventDefault();
        });
    });

    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.matches('[data-preview-form]')) {
                event.preventDefault();
                return;
            }

            const button = form.querySelector('[data-submit-button]');
            if (!button || button.disabled) return;
            button.disabled = true;
            button.classList.add('is-loading');
            const label = button.querySelector('span');
            if (label) label.textContent = 'Подождите…';
        });
    });

    const toast = document.querySelector('[data-toast]');
    if (toast) {
        const dismiss = () => {
            toast.classList.add('is-hiding');
            window.setTimeout(() => toast.remove(), 220);
        };
        toast.querySelector('[data-toast-close]')?.addEventListener('click', dismiss);
        const messageLength = toast.textContent.trim().length;
        window.setTimeout(dismiss, messageLength > 160 ? 9000 : 5500);
    }

    const renderClock = () => {
        const clock = document.querySelector('[data-kyiv-clock]');
        if (!clock) return;
        clock.textContent = new Intl.DateTimeFormat('ru-RU', {
            timeZone: 'Europe/Kyiv',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        }).format(new Date());
    };
    renderClock();
    window.setInterval(renderClock, 1000);

    document.querySelectorAll('[data-rich-editor]').forEach((editor) => {
        const surface = editor.querySelector('[data-editor-surface]');
        const input = editor.querySelector('[data-editor-input]');
        const form = editor.closest('form');
        if (!surface || !input) return;

        surface.innerHTML = input.value || '';

        const sync = () => {
            input.value = surface.innerHTML.trim();
        };

        surface.addEventListener('input', sync);
        editor.querySelectorAll('[data-editor-command]').forEach((button) => {
            button.addEventListener('click', () => {
                surface.focus();
                document.execCommand(button.dataset.editorCommand, false);
                sync();
            });
        });
        editor.querySelector('[data-editor-link]')?.addEventListener('click', () => {
            const url = window.prompt('Введите ссылку, начиная с https://');
            if (!url) return;
            surface.focus();
            document.execCommand('createLink', false, url.trim());
            sync();
        });
        form?.addEventListener('submit', sync);
    });

    document.querySelectorAll('[data-account-form]').forEach((form) => {
        const apiSelect = form.querySelector('[data-api-select]');
        const newApiFields = form.querySelector('[data-new-api-fields]');
        const phoneField = form.querySelector('[data-phone-field]');

        const updateApiFields = () => {
            if (!newApiFields || !apiSelect) return;
            const createNew = !apiSelect.value;
            newApiFields.hidden = !createNew;
            newApiFields.querySelectorAll('input').forEach((input) => {
                input.required = createNew;
            });
        };

        const updatePhoneField = () => {
            const method = form.querySelector('input[name="auth_method"]:checked')?.value;
            if (!phoneField) return;
            phoneField.hidden = method !== 'phone';
            const input = phoneField.querySelector('input[name="phone"]');
            if (input && !form.querySelector('input[name="_method"]')) input.required = method === 'phone';
        };

        apiSelect?.addEventListener('change', updateApiFields);
        form.querySelectorAll('input[name="auth_method"]').forEach((input) => input.addEventListener('change', updatePhoneField));
        updateApiFields();
        updatePhoneField();
    });

    document.querySelectorAll('[data-open-modal-on-load]').forEach((element) => {
        openModal(element.dataset.openModalOnLoad, element.dataset.modalScrollTo || null);
    });

    const qrTargets = document.querySelectorAll('[data-qr-url]');
    if (qrTargets.length) {
        qrTargets.forEach((target) => {
            const canvas = document.createElement('canvas');
            target.replaceChildren(canvas);
            QRCode.toCanvas(canvas, target.dataset.qrUrl, {
                width: 220,
                errorCorrectionLevel: 'M',
                margin: 1,
            }, (error) => {
                if (error) target.textContent = 'Не удалось построить QR-код.';
            });
        });
    }
});
