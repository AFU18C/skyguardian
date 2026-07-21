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
$('[data-add-source]')?.addEventListener('click', () => openModal($('#sourceModal')));

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

const sourceForm = $('[data-source-form]');
sourceForm?.addEventListener('submit', event => {
  event.preventDefault();
  const values = Object.fromEntries(new FormData(sourceForm));
  if (!values.name?.trim() || !values.source?.trim() || !values.account?.trim()) {
    toast('Заполните все поля канала данных');
    return;
  }
  if (customTextToggle?.checked && !values.custom_text?.trim()) {
    toast('Введите собственный текст');
    customTextInput?.focus();
    return;
  }
  closeModal($('#sourceModal'));
  toast('Макет канала данных сохранён');
});
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
