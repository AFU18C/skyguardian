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
