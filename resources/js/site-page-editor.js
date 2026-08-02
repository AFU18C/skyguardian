const onSiteEditorReady = (callback) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
        callback();
    }
};

onSiteEditorReady(() => {
    const root = document.querySelector('[data-site-page-editor]');
    if (!root) return;

    const blocksRoot = root.querySelector('[data-blocks-root]');
    const emptyState = root.querySelector('[data-blocks-empty]');
    const hiddenInput = root.querySelector('[name="blocks_json"]');
    const initialScript = root.querySelector('[data-initial-blocks]');
    const typeSelect = root.querySelector('[data-add-block-type]');
    const addButton = root.querySelector('[data-add-block]');
    const uploadUrl = root.dataset.uploadUrl;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const labels = {
        heading: 'Заголовок',
        text: 'Текст',
        image: 'Изображение',
        gallery: 'Галерея',
        video: 'Видео',
        button: 'Кнопка',
        list: 'Список',
        card: 'Информационная карточка',
        divider: 'Разделитель',
        columns: 'Две колонки',
        contacts: 'Контакты',
        telegram: 'Telegram-ссылка',
        alert_map: 'Карта тревог',
        html: 'HTML-блок',
    };

    let blocks = [];
    try {
        blocks = JSON.parse(initialScript?.textContent || '[]');
        if (!Array.isArray(blocks)) blocks = [];
    } catch {
        blocks = [];
    }

    const uid = () => `block-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;

    const slugify = (value) => {
        const map = {
            а: 'a', б: 'b', в: 'v', г: 'g', ґ: 'g', д: 'd', е: 'e', ё: 'e', є: 'ye',
            ж: 'zh', з: 'z', и: 'i', і: 'i', ї: 'yi', й: 'y', к: 'k', л: 'l', м: 'm',
            н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f', х: 'h',
            ц: 'ts', ч: 'ch', ш: 'sh', щ: 'shch', ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya',
        };

        return String(value || '')
            .toLowerCase()
            .split('')
            .map((char) => map[char] ?? char)
            .join('')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 180);
    };

    const titleInput = root.querySelector('[name="title"]');
    const slugInput = root.querySelector('[name="slug"]');
    let slugWasEdited = Boolean(slugInput?.value);
    slugInput?.addEventListener('input', () => {
        slugWasEdited = true;
        slugInput.value = slugify(slugInput.value);
    });
    titleInput?.addEventListener('input', () => {
        if (!slugWasEdited && slugInput) slugInput.value = slugify(titleInput.value);
    });

    const field = ({ label, key, type = 'text', value = '', options = [], placeholder = '', rows = 4, multiple = false }) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'sg-field';
        const labelNode = document.createElement('label');
        labelNode.textContent = label;
        wrapper.append(labelNode);

        let input;
        if (type === 'textarea') {
            input = document.createElement('textarea');
            input.rows = rows;
            input.value = value ?? '';
        } else if (type === 'select') {
            input = document.createElement('select');
            options.forEach(([optionValue, optionLabel]) => {
                const option = document.createElement('option');
                option.value = optionValue;
                option.textContent = optionLabel;
                option.selected = String(optionValue) === String(value ?? '');
                input.append(option);
            });
        } else {
            input = document.createElement('input');
            input.type = type;
            if (type === 'checkbox') {
                input.checked = Boolean(value);
            } else {
                input.value = value ?? '';
            }
            if (multiple) input.multiple = true;
        }

        input.dataset.blockKey = key;
        if (placeholder) input.placeholder = placeholder;
        wrapper.append(input);
        return wrapper;
    };

    const blockFields = (block) => {
        const data = block.data || {};
        const fragment = document.createDocumentFragment();
        const grid = document.createElement('div');
        grid.className = 'site-block-grid';

        const append = (...items) => items.filter(Boolean).forEach((item) => grid.append(item));

        switch (block.type) {
            case 'heading':
                append(
                    field({ label: 'Уровень заголовка', key: 'level', type: 'select', value: data.level || '2', options: [['2', 'H2'], ['3', 'H3'], ['4', 'H4']] }),
                    field({ label: 'Текст заголовка', key: 'text', value: data.text || '', placeholder: 'Заголовок раздела' }),
                );
                break;
            case 'text':
                append(
                    field({ label: 'Текст', key: 'content', type: 'textarea', value: data.content || '', rows: 8, placeholder: 'Текст блока' }),
                    field({ label: 'Выравнивание', key: 'align', type: 'select', value: data.align || 'left', options: [['left', 'По левому краю'], ['center', 'По центру'], ['right', 'По правому краю']] }),
                );
                break;
            case 'image':
                append(
                    field({ label: 'URL изображения', key: 'src', value: data.src || '', placeholder: '/storage/site/content/image.jpg' }),
                    field({ label: 'Описание изображения', key: 'alt', value: data.alt || '' }),
                    field({ label: 'Подпись', key: 'caption', value: data.caption || '' }),
                );
                break;
            case 'gallery':
                append(
                    field({ label: 'Изображения — по одному URL в строке', key: 'images', type: 'textarea', value: data.images || '', rows: 7 }),
                    field({ label: 'Количество колонок', key: 'columns', type: 'select', value: data.columns || '3', options: [['2', '2'], ['3', '3'], ['4', '4']] }),
                );
                break;
            case 'video':
                append(
                    field({ label: 'Ссылка на YouTube, Vimeo или MP4', key: 'url', value: data.url || '' }),
                    field({ label: 'Подпись', key: 'caption', value: data.caption || '' }),
                );
                break;
            case 'button':
                append(
                    field({ label: 'Текст кнопки', key: 'label', value: data.label || '' }),
                    field({ label: 'Ссылка', key: 'url', value: data.url || '' }),
                    field({ label: 'Стиль', key: 'style', type: 'select', value: data.style || 'primary', options: [['primary', 'Основная'], ['secondary', 'Контурная']] }),
                    field({ label: 'Открывать в новой вкладке', key: 'new_tab', type: 'checkbox', value: Boolean(data.new_tab) }),
                );
                break;
            case 'list':
                append(
                    field({ label: 'Пункты — каждый с новой строки', key: 'items', type: 'textarea', value: data.items || '', rows: 7 }),
                    field({ label: 'Нумерованный список', key: 'ordered', type: 'checkbox', value: Boolean(data.ordered) }),
                );
                break;
            case 'card':
                append(
                    field({ label: 'Заголовок', key: 'title', value: data.title || '' }),
                    field({ label: 'Текст', key: 'text', type: 'textarea', value: data.text || '', rows: 5 }),
                    field({ label: 'Текст ссылки', key: 'link_label', value: data.link_label || '' }),
                    field({ label: 'Ссылка', key: 'link_url', value: data.link_url || '' }),
                );
                break;
            case 'divider':
                append(field({ label: 'Стиль', key: 'style', type: 'select', value: data.style || 'solid', options: [['solid', 'Сплошной'], ['dashed', 'Пунктир'], ['ornament', 'Декоративный']] }));
                break;
            case 'columns':
                append(
                    field({ label: 'Левая колонка — разрешённый HTML', key: 'left_html', type: 'textarea', value: data.left_html || '', rows: 8 }),
                    field({ label: 'Правая колонка — разрешённый HTML', key: 'right_html', type: 'textarea', value: data.right_html || '', rows: 8 }),
                );
                break;
            case 'contacts':
                append(
                    field({ label: 'Адрес', key: 'address', value: data.address || '' }),
                    field({ label: 'Телефон', key: 'phone', value: data.phone || '' }),
                    field({ label: 'Email', key: 'email', type: 'email', value: data.email || '' }),
                );
                break;
            case 'telegram':
                append(
                    field({ label: 'Название', key: 'label', value: data.label || 'Telegram' }),
                    field({ label: 'Ссылка', key: 'url', value: data.url || 'https://t.me/' }),
                    field({ label: 'Описание', key: 'text', value: data.text || '' }),
                );
                break;
            case 'alert_map': {
                append(
                    field({ label: 'Заголовок', key: 'title', value: data.title ?? 'Карта воздушных тревог Украины' }),
                    field({ label: 'Показывать заголовок карты', key: 'show_title', type: 'checkbox', value: data.show_title ?? true }),
                    field({
                        label: 'Версия карты',
                        key: 'mode',
                        type: 'select',
                        value: data.mode || 'lite',
                        options: [['lite', 'Лайт-версия'], ['full', 'Полная версия']],
                    }),
                    field({
                        label: 'Отображение карты',
                        key: 'layout',
                        type: 'select',
                        value: data.layout || 'contained',
                        options: [['contained', 'Внутри блока — как сейчас'], ['full', 'На весь блок']],
                    }),
                    field({
                        label: 'Размер карты',
                        key: 'size',
                        type: 'select',
                        value: data.size || 'standard',
                        options: [['compact', 'Компактный'], ['standard', 'Стандартный'], ['large', 'Большой']],
                    }),
                );

                const note = document.createElement('p');
                note.className = 'site-block-help';
                note.textContent = 'Обе версии карты обновляются автоматически. Вариант «На весь блок» убирает боковые поля вокруг карты.';
                grid.append(note);
                break;
            }
            case 'html':
                append(field({ label: 'HTML', key: 'html', type: 'textarea', value: data.html || '', rows: 12, placeholder: '<p>Разрешённый HTML-код</p>' }));
                break;
            default:
                break;
        }

        if (['image', 'gallery'].includes(block.type)) {
            const uploadWrapper = document.createElement('div');
            uploadWrapper.className = 'sg-field';
            const label = document.createElement('label');
            label.textContent = block.type === 'gallery' ? 'Загрузить изображения' : 'Загрузить изображение';
            const uploadInput = document.createElement('input');
            uploadInput.type = 'file';
            uploadInput.accept = 'image/*';
            uploadInput.multiple = block.type === 'gallery';
            uploadInput.dataset.blockUpload = '';
            const status = document.createElement('div');
            status.className = 'site-block-upload-status';
            status.dataset.uploadStatus = '';
            uploadWrapper.append(label, uploadInput, status);
            grid.append(uploadWrapper);
        }

        fragment.append(grid);
        return fragment;
    };

    const sync = () => {
        hiddenInput.value = JSON.stringify(blocks);
        emptyState.hidden = blocks.length > 0;
    };

    const readCard = (card, index) => {
        const block = blocks[index];
        if (!block) return;
        block.data ||= {};
        card.querySelectorAll('[data-block-key]').forEach((input) => {
            const key = input.dataset.blockKey;
            block.data[key] = input.type === 'checkbox' ? input.checked : input.value;
        });
        const hiddenToggle = card.querySelector('[data-block-hidden]');
        block.hidden = Boolean(hiddenToggle?.checked);
        card.classList.toggle('is-hidden', block.hidden);
        sync();
    };

    const uploadFiles = async (input, card, index) => {
        if (!input.files?.length || !uploadUrl) return;
        const status = card.querySelector('[data-upload-status]');
        status.textContent = 'Загрузка…';
        status.className = 'site-block-upload-status';
        input.disabled = true;

        const body = new FormData();
        Array.from(input.files).forEach((file) => body.append('media[]', file));

        try {
            const response = await fetch(uploadUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.message || 'Ошибка загрузки.');

            const urls = (payload.files || []).map((file) => file.url).filter(Boolean);
            const block = blocks[index];
            if (block.type === 'image') {
                block.data.src = urls[0] || block.data.src || '';
            } else {
                const current = String(block.data.images || '').split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
                block.data.images = [...current, ...urls].join('\n');
            }
            status.textContent = `Загружено: ${urls.length}`;
            status.classList.add('is-success');
            render();
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : 'Ошибка загрузки.';
            status.classList.add('is-error');
        } finally {
            input.disabled = false;
            input.value = '';
        }
    };

    const render = () => {
        blocksRoot.innerHTML = '';
        blocks.forEach((block, index) => {
            block.id ||= uid();
            block.data ||= {};
            const card = document.createElement('section');
            card.className = `site-block-card${block.hidden ? ' is-hidden' : ''}`;
            card.dataset.blockIndex = String(index);

            const head = document.createElement('header');
            head.className = 'site-block-card-head';
            const title = document.createElement('strong');
            title.textContent = labels[block.type] || block.type;
            const counter = document.createElement('small');
            counter.textContent = `#${index + 1}`;

            const hiddenLabel = document.createElement('label');
            hiddenLabel.className = 'site-menu-flags';
            const hiddenInputNode = document.createElement('input');
            hiddenInputNode.type = 'checkbox';
            hiddenInputNode.checked = Boolean(block.hidden);
            hiddenInputNode.dataset.blockHidden = '';
            const hiddenText = document.createElement('span');
            hiddenText.textContent = 'Скрыт';
            hiddenLabel.append(hiddenInputNode, hiddenText);

            const actions = document.createElement('div');
            actions.className = 'site-block-card-actions';
            const makeButton = (text, titleText, action, danger = false) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = `site-block-icon-button${danger ? ' is-danger' : ''}`;
                button.textContent = text;
                button.title = titleText;
                button.dataset.blockAction = action;
                return button;
            };
            actions.append(
                makeButton('↑', 'Переместить выше', 'up'),
                makeButton('↓', 'Переместить ниже', 'down'),
                makeButton('⧉', 'Дублировать', 'duplicate'),
                makeButton('×', 'Удалить блок', 'delete', true),
            );
            head.append(title, counter, hiddenLabel, actions);

            const body = document.createElement('div');
            body.className = 'site-block-card-body';
            body.append(blockFields(block));
            card.append(head, body);

            card.addEventListener('input', () => readCard(card, index));
            card.addEventListener('change', (event) => {
                if (event.target.matches('[data-block-upload]')) {
                    uploadFiles(event.target, card, index);
                } else {
                    readCard(card, index);
                }
            });
            card.addEventListener('click', (event) => {
                const button = event.target.closest('[data-block-action]');
                if (!button) return;
                const action = button.dataset.blockAction;
                if (action === 'up' && index > 0) {
                    [blocks[index - 1], blocks[index]] = [blocks[index], blocks[index - 1]];
                } else if (action === 'down' && index < blocks.length - 1) {
                    [blocks[index + 1], blocks[index]] = [blocks[index], blocks[index + 1]];
                } else if (action === 'duplicate') {
                    const copy = JSON.parse(JSON.stringify(block));
                    copy.id = uid();
                    blocks.splice(index + 1, 0, copy);
                } else if (action === 'delete') {
                    if (!window.confirm('Удалить этот блок со страницы?')) return;
                    blocks.splice(index, 1);
                }
                render();
            });

            blocksRoot.append(card);
        });
        sync();
    };

    addButton?.addEventListener('click', () => {
        const type = typeSelect?.value;
        if (!type || !labels[type]) return;
        blocks.push({ id: uid(), type, hidden: false, data: {} });
        render();
        blocksRoot.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    root.querySelector('form')?.addEventListener('submit', sync);
    render();
});
