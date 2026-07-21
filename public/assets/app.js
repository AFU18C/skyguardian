const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

function toast(message) {
  const item = document.createElement('div');
  item.className = 'toast';
  item.textContent = message;
  $('#toasts').append(item);
  setTimeout(() => item.remove(), 3200);
}

function openModal(modal) {
  modal.scrollTop = 0;
  const card = modal.querySelector('.modal-card');
  if (card) card.scrollTop = 0;
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
}

function closeModal(modal) {
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
}

const sidebar = $('#sidebar');
$('[data-menu]')?.addEventListener('click', () => sidebar.classList.add('open'));
$('[data-menu-close]')?.addEventListener('click', () => sidebar.classList.remove('open'));

$$('[data-toast]').forEach(button => button.addEventListener('click', () => toast(button.dataset.toast)));

$$('[data-spoiler-button]').forEach(button => {
  button.addEventListener('click', () => button.closest('[data-spoiler]').classList.toggle('open'));
});

$('[data-password]')?.addEventListener('click', event => {
  const input = event.currentTarget.previousElementSibling;
  input.type = input.type === 'password' ? 'text' : 'password';
});

const apiForm = $('[data-api-form]');
apiForm?.addEventListener('submit', event => {
  event.preventDefault();
  const values = Object.fromEntries(new FormData(apiForm));
  if (!values.name.trim() || !values.api_id.trim() || !values.api_hash.trim()) {
    toast('Заполните все поля Telegram API');
    return;
  }

  const panel = apiForm.closest('.api-panel');
  const status = $('.status-pill', panel);
  status.classList.remove('off');
  status.classList.add('on');
  status.innerHTML = '<i></i>Сохранено';
  const qrButton = $('[data-qr]');
  qrButton.disabled = false;
  toast('API-данные успешно сохранены');
});

const account = $('[data-account]');
$('[data-account-edit]')?.addEventListener('click', event => {
  event.currentTarget.classList.toggle('open');
  $('[data-account-details]', account).classList.toggle('open');
});

$('[data-add-connection]')?.addEventListener('click', () => openModal($('#connectionModal')));

const sourceModal = $('#sourceModal');
const sourceForm = $('[data-source-form]');
const sourceList = $('[data-source-list]');
const sourceEmpty = $('[data-source-empty]');
const sourceDeleteButton = $('[data-source-delete]');
const sourceSaveButton = $('[data-source-save]');
const sourceModalLabel = $('[data-source-modal-label]');
const sourceScope = sourceList?.dataset.sourceScope || 'sources';
const sourceStorageKey = 'skyguardian:' + sourceScope + ':channels';
let sources = [];

const publicationFormatLabels = {
  original: 'Оригинал полностью',
  text: 'Только текст',
  text_without_links: 'Только текст без ссылок',
  media: 'Только медиа',
  text_and_media: 'Текст и медиа'
};
const processingStartLabels = {
  new: 'Только новые сообщения',
  last_5: 'Последние 5 сообщений',
  last_10: 'Последние 10 сообщений',
  last_20: 'Последние 20 сообщений'
};

function readSources() {
  try {
    const saved = JSON.parse(localStorage.getItem(sourceStorageKey) || '[]');
    sources = Array.isArray(saved) ? saved : [];
  } catch {
    sources = [];
  }
}

function writeSources() {
  try {
    localStorage.setItem(sourceStorageKey, JSON.stringify(sources));
  } catch {
    toast('Не удалось сохранить данные в браузере');
  }
}

function createSourceCard(source) {
  const card = document.createElement('article');
  card.className = 'source-card';
  card.dataset.sourceId = source.id;

  const icon = document.createElement('div');
  icon.className = 'source-card-icon';
  icon.textContent = '◉';

  const info = document.createElement('div');
  info.className = 'source-card-info';
  const name = document.createElement('strong');
  name.textContent = source.name;
  const route = document.createElement('span');
  route.textContent = source.source + ' → ' + source.destination;
  const details = document.createElement('small');
  const unit = source.check_frequency_unit === 'hours' ? 'ч.' : 'сек.';
  details.textContent = (publicationFormatLabels[source.publication_format] || source.publication_format) + ' · ' + source.check_frequency + ' ' + unit + ' · ' + (processingStartLabels[source.processing_start] || source.processing_start);
  info.append(name, route, details);

  const status = document.createElement('span');
  status.className = 'status-pill ' + (source.account ? 'on' : 'off');
  const dot = document.createElement('i');
  status.append(dot, document.createTextNode(source.account ? 'Работает' : 'Не работает'));

  const edit = document.createElement('button');
  edit.className = 'source-edit-button';
  edit.type = 'button';
  edit.setAttribute('aria-label', 'Редактировать канал ' + source.name);
  edit.title = 'Редактировать';
  edit.textContent = '✎';
  edit.addEventListener('click', () => openSourceEditor(source.id));

  card.append(icon, info, status, edit);
  return card;
}

function renderSources() {
  if (!sourceList) return;
  sourceList.querySelectorAll('[data-source-id]').forEach(card => card.remove());
  sourceEmpty.hidden = sources.length > 0;
  sources.forEach(source => sourceList.append(createSourceCard(source)));
}

function resetSourceForm() {
  if (!sourceForm) return;
  sourceForm.reset();
  sourceForm.elements.source_id.value = '';
  sourceDeleteButton.hidden = true;
  sourceSaveButton.textContent = 'Добавить';
  sourceModalLabel.textContent = 'ДОБАВЛЕНИЕ КАНАЛА';
  if (customTextEditor) customTextEditor.hidden = true;
  if (customTextPreview) customTextPreview.hidden = true;
  syncFrequencyLimits();
}

function openSourceEditor(id) {
  const source = sources.find(item => item.id === id);
  if (!source || !sourceForm) return;
  resetSourceForm();
  Object.entries(source).forEach(([key, value]) => {
    const field = sourceForm.elements.namedItem(key);
    if (!field) return;
    if (field.type === 'checkbox') field.checked = Boolean(value);
    else field.value = value ?? '';
  });
  sourceDeleteButton.hidden = false;
  sourceSaveButton.textContent = 'Сохранить';
  sourceModalLabel.textContent = 'РЕДАКТИРОВАНИЕ КАНАЛА';
  if (customTextToggle) customTextToggle.dispatchEvent(new Event('change'));
  openModal(sourceModal);
}

$('[data-add-source]')?.addEventListener('click', () => {
  resetSourceForm();
  openModal(sourceModal);
});

const frequencyValue = $('[data-frequency-value]');
const frequencyUnit = $('[data-frequency-unit]');
const frequencyHint = $('[data-frequency-hint]');

function syncFrequencyLimits() {
  if (!frequencyValue || !frequencyUnit) return;
  const isHours = frequencyUnit.value === 'hours';
  frequencyValue.min = isHours ? '1' : '3';
  frequencyValue.max = isHours ? '24' : '86400';
  frequencyValue.placeholder = isHours ? 'От 1 до 24' : 'От 3 до 86400';
  if (frequencyHint) frequencyHint.textContent = isHours ? 'Допустимо: от 1 до 24 часов' : 'Допустимо: от 3 до 86 400 секунд';
  const value = Number(frequencyValue.value);
  if (frequencyValue.value && (value < Number(frequencyValue.min) || value > Number(frequencyValue.max))) frequencyValue.value = '';
}

frequencyUnit?.addEventListener('change', syncFrequencyLimits);
syncFrequencyLimits();

const customTextToggle = $('[data-custom-text-toggle]');
const customTextEditor = $('[data-custom-text-editor]');
const customTextInput = $('[data-custom-text-input]');
const customTextPosition = $('[data-custom-text-position]');
const customTextPreview = $('[data-custom-text-preview]');
const customTextPreviewContent = $('[data-custom-text-preview-content]');

function renderCustomTextPreview() {
  if (!customTextPreviewContent) return;
  customTextPreviewContent.replaceChildren();
  const source = document.createElement('span');
  source.className = 'preview-source';
  source.textContent = 'Пример текста сообщения из канала';
  const custom = document.createElement('span');
  custom.className = 'preview-custom';
  custom.textContent = customTextInput?.value.trim() || 'Ваш собственный текст';
  const divider = document.createElement('span');
  divider.className = 'preview-divider';
  if (customTextPosition?.value === 'before') customTextPreviewContent.append(custom, divider, source);
  else customTextPreviewContent.append(source, divider, custom);
}

customTextToggle?.addEventListener('change', () => {
  customTextEditor.hidden = !customTextToggle.checked;
  if (!customTextToggle.checked) customTextPreview.hidden = true;
  if (customTextToggle.checked) customTextInput?.focus();
  renderCustomTextPreview();
});

$('[data-custom-text-preview-button]')?.addEventListener('click', () => {
  customTextPreview.hidden = !customTextPreview.hidden;
  renderCustomTextPreview();
});
customTextInput?.addEventListener('input', renderCustomTextPreview);
customTextPosition?.addEventListener('change', renderCustomTextPreview);

$$('[data-editor-wrap]').forEach(button => button.addEventListener('click', () => {
  if (!customTextInput) return;
  const marker = button.dataset.editorWrap;
  const start = customTextInput.selectionStart;
  const end = customTextInput.selectionEnd;
  const selected = customTextInput.value.slice(start, end) || 'текст';
  customTextInput.setRangeText(marker + selected + marker, start, end, 'select');
  customTextInput.focus();
  customTextInput.dispatchEvent(new Event('input'));
}));

$('[data-editor-link]')?.addEventListener('click', () => {
  if (!customTextInput) return;
  const start = customTextInput.selectionStart;
  const end = customTextInput.selectionEnd;
  const selected = customTextInput.value.slice(start, end) || 'текст ссылки';
  customTextInput.setRangeText('[' + selected + '](https://)', start, end, 'select');
  customTextInput.focus();
  customTextInput.dispatchEvent(new Event('input'));
});

sourceForm?.addEventListener('submit', event => {
  event.preventDefault();
  if (!sourceForm.checkValidity()) {
    sourceForm.reportValidity();
    toast('Заполните обязательные поля');
    return;
  }
  const values = Object.fromEntries(new FormData(sourceForm));
  if (customTextToggle?.checked && !values.custom_text?.trim()) {
    toast('Введите собственный текст');
    customTextInput?.focus();
    return;
  }

  const source = {
    id: values.source_id || (globalThis.crypto?.randomUUID?.() || String(Date.now())),
    name: values.name.trim(),
    source: values.source.trim(),
    account: values.account || '',
    destination: values.destination.trim(),
    publication_format: values.publication_format,
    check_frequency: values.check_frequency,
    check_frequency_unit: values.check_frequency_unit,
    processing_start: values.processing_start,
    keywords: values.keywords?.trim() || '',
    stop_words: values.stop_words?.trim() || '',
    custom_text_enabled: customTextToggle?.checked || false,
    custom_text_position: values.custom_text_position || 'after',
    custom_text: values.custom_text?.trim() || ''
  };

  const index = sources.findIndex(item => item.id === source.id);
  if (index >= 0) sources[index] = source;
  else sources.push(source);
  writeSources();
  renderSources();
  closeModal(sourceModal);
  toast('Сохранено');
});

sourceDeleteButton?.addEventListener('click', () => {
  const id = sourceForm?.elements.source_id.value;
  if (!id) return;
  sources = sources.filter(item => item.id !== id);
  writeSources();
  renderSources();
  closeModal(sourceModal);
  toast('Удалено');
});

readSources();
renderSources();
$('[data-qr]')?.addEventListener('click', () => openModal($('#qrModal')));
$('[data-confirm-delete]')?.addEventListener('click', () => openModal($('#deleteModal')));
$$('[data-modal-close]').forEach(button => button.addEventListener('click', () => closeModal(button.closest('.modal'))));

$('[data-save-account]')?.addEventListener('click', () => toast('Изменения успешно сохранены'));
$('[data-delete]')?.addEventListener('click', event => {
  closeModal(event.currentTarget.closest('.modal'));
  toast('Демонстрация удаления завершена');
});

document.addEventListener('keydown', event => {
  if (event.key === 'Escape') $$('.modal.open').forEach(closeModal);
});
