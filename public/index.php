<?php

declare(strict_types=1);

use SkyGuardian\Telegram\ChannelPublisher;
use SkyGuardian\Telegram\QrLoginService;

session_start();

const APP_NAME = 'SkyGuardian';
const MAX_CHANNELS = 10;
const MAX_ACCOUNTS = 10;

$root = dirname(__DIR__);
$storageFile = $root . '/storage/skyguardian.json';
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

function defaultData(): array
{
    return [
        'news' => ['channels' => [], 'settings' => []],
        'alerts' => ['channels' => [], 'settings' => []],
        'accounts' => [],
    ];
}

function loadData(string $file): array
{
    if (!is_file($file)) {
        return defaultData();
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? array_replace_recursive(defaultData(), $decoded) : defaultData();
}

function saveData(string $file, array $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
    rename($tmp, $file);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['csrf'];
}

function verifyCsrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        http_response_code(419);
        renderError(419, 'Сессия устарела', 'Обновите страницу и повторите действие.');
        exit;
    }
}

function redirect(string $path, ?string $message = null, string $type = 'success'): never
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }
    header('Location: ' . $path);
    exit;
}

function isAuthenticated(): bool
{
    return ($_SESSION['authenticated'] ?? false) === true;
}

function requireAuth(): void
{
    if (!isAuthenticated()) {
        redirect('/login');
    }
}

function routePath(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return is_string($path) && $path !== '' ? rtrim($path, '/') ?: '/' : '/';
}

function selected(string $left, string $right): string
{
    return $left === $right ? ' selected' : '';
}

function checked(bool $value): string
{
    return $value ? ' checked' : '';
}

function renderHead(string $title): void
{
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . ' — ' . APP_NAME . '</title>';
    echo '<link rel="stylesheet" href="/assets/app.css?v=1784583104"></head><body>';
}

function renderFoot(): void
{
    echo '<script src="/assets/app.js?v=1784583104"></script></body></html>';
}

function renderError(int $code, string $title, string $text): void
{
    renderHead((string) $code);
    echo '<main class="state-page"><section class="state-card"><div class="state-code">' . $code . '</div>';
    echo '<h1>' . e($title) . '</h1><p>' . e($text) . '</p><a class="button primary" href="/app">На главную</a></section></main>';
    renderFoot();
}

function renderLayoutStart(string $title, string $active): void
{
    renderHead($title);
    echo '<div class="app-shell"><aside class="sidebar" id="sidebar"><div class="sidebar-brand"><div class="brand-mark small">SG</div><strong>SkyGuardian</strong></div><nav>';
    foreach ([
        ['/app', 'Главная'], ['/news/channels', 'Новости — каналы'], ['/news/settings', 'Новости — настройка'],
        ['/alerts/channels', 'Тревога — каналы'], ['/alerts/settings', 'Тревога — настройка'], ['/group', 'Управление группой'],
    ] as [$href, $label]) {
        echo '<a class="nav-link' . ($active === $href ? ' active' : '') . '" href="' . $href . '">' . e($label) . '</a>';
    }
    echo '<a class="nav-link logout" href="/logout">Выйти</a></nav></aside><div class="main-column">';
    echo '<header class="topbar"><button id="menuToggle" class="icon-button" type="button">☰</button><h1>' . e($title) . '</h1></header><main class="content">';
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        if (is_array($flash)) {
            echo '<div class="notice ' . e((string) ($flash['type'] ?? 'success')) . '" data-autohide>' . e((string) ($flash['message'] ?? '')) . '</div>';
        } else {
            echo '<div class="notice success" data-autohide>' . e((string) $flash) . '</div>';
        }
    }
}

function renderLayoutEnd(): void
{
    echo '</main></div></div><div class="sidebar-overlay" id="sidebarOverlay"></div>';
    echo '<dialog id="confirmDialog" class="modal"><div class="modal-body"><h2>Подтвердите удаление</h2><p>Настройки будут удалены.</p><div class="modal-actions"><button class="button ghost" type="button" data-close-dialog>Отмена</button><button class="button danger" type="button" id="confirmDelete">Удалить</button></div></div></dialog>';
    renderFoot();
}

function findAccount(array $accounts, string $id): ?array
{
    foreach ($accounts as $account) {
        if ((string) ($account['id'] ?? '') === $id) {
            return $account;
        }
    }
    return null;
}

function normalizeInterval(int $value, string $unit): array
{
    $limits = ['seconds' => [3, 59], 'minutes' => [1, 59], 'hours' => [1, 12]];
    if (!isset($limits[$unit])) {
        $unit = 'minutes';
    }
    [$min, $max] = $limits[$unit];
    return [max($min, min($max, $value)), $unit];
}

function channelPayload(array $input, ?array $existing = null): array
{
    [$intervalValue, $intervalUnit] = normalizeInterval((int) ($input['interval_value'] ?? 3), (string) ($input['interval_unit'] ?? 'seconds'));
    return array_merge($existing ?? [], [
        'id' => trim((string) ($input['id'] ?? '')) ?: (string) ($existing['id'] ?? bin2hex(random_bytes(8))),
        'name' => trim((string) ($input['name'] ?? '')),
        'source' => trim((string) ($input['source'] ?? '')),
        'account_id' => trim((string) ($input['account_id'] ?? '')),
        'destination' => trim((string) ($input['destination'] ?? '')),
        'interval_value' => $intervalValue,
        'interval_unit' => $intervalUnit,
        'publish_mode' => trim((string) ($input['publish_mode'] ?? 'forward_original')),
        'only_new' => isset($input['only_new']),
        'keywords' => trim((string) ($input['keywords'] ?? '')),
        'excluded_words' => trim((string) ($input['excluded_words'] ?? '')),
        'footer_html' => trim((string) ($input['footer_html'] ?? '')),
        'active' => ($input['active'] ?? '1') === '1',
    ]);
}

function accountPayload(array $input, ?array $existing = null): array
{
    $apiHash = trim((string) ($input['api_hash'] ?? ''));
    if ($apiHash === '' && $existing !== null) {
        $apiHash = (string) ($existing['api_hash'] ?? '');
    }
    return array_merge($existing ?? [], [
        'id' => trim((string) ($input['id'] ?? '')) ?: (string) ($existing['id'] ?? bin2hex(random_bytes(8))),
        'name' => trim((string) ($input['name'] ?? '')),
        'phone' => trim((string) ($input['phone'] ?? '')),
        'api_id' => trim((string) ($input['api_id'] ?? '')),
        'api_hash' => $apiHash,
        'active' => ($input['active'] ?? '1') === '1',
        'connected' => (bool) ($existing['connected'] ?? false),
    ]);
}

function renderChannelForm(string $section, array $channel, array $accounts, bool $isNew = false): void
{
    $id = (string) ($channel['id'] ?? 'new');
    $active = (bool) ($channel['active'] ?? true);
    $selectedId = (string) ($channel['account_id'] ?? '');
    echo '<article class="accordion-card' . ($isNew ? ' open' : '') . '" data-accordion><div class="accordion-header">';
    echo '<button type="button" class="accordion-trigger" aria-expanded="' . ($isNew ? 'true' : 'false') . '"><span><strong>' . e((string) ($channel['name'] ?? 'Новый канал')) . '</strong><small>' . e((string) ($channel['source'] ?? 'Источник не указан')) . '</small></span><span class="chevron">⌄</span></button>';
    echo '<label class="switch"><input class="channel-toggle" type="checkbox" data-section="' . e($section) . '" data-id="' . e($id) . '"' . checked($active) . '><span></span></label><button type="button" class="icon-button edit-trigger">✎</button></div>';
    echo '<div class="accordion-panel"><form method="post" action="/' . e($section) . '/channels/save" class="channel-form form-grid">';
    echo '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="id" value="' . e($id === 'new' ? '' : $id) . '">';
    echo '<div class="grid-2"><label>Название канала <span class="required">*</span><input required name="name" value="' . e((string) ($channel['name'] ?? '')) . '"></label>';
    echo '<label>Источник <span class="required">*</span><input required name="source" placeholder="https://t.me/channel или @channel" value="' . e((string) ($channel['source'] ?? '')) . '"></label>';
    echo '<label>Технический аккаунт <span class="required">*</span><select required name="account_id"><option value="">Выберите аккаунт</option>';
    foreach ($accounts as $account) {
        $label = (string) ($account['name'] ?? 'Аккаунт');
        if (!(bool) ($account['active'] ?? false)) {
            $label .= ' — отключён';
        }
        if (!(bool) ($account['connected'] ?? false)) {
            $label .= ' — не подключён';
        }
        echo '<option value="' . e((string) $account['id']) . '"' . selected($selectedId, (string) $account['id']) . '>' . e($label) . '</option>';
    }
    echo '</select></label><label>Канал или группа публикации <span class="required">*</span><input required name="destination" value="' . e((string) ($channel['destination'] ?? '')) . '" placeholder="https://t.me/group или @group"></label></div>';
    echo '<div class="grid-2"><fieldset><legend>Частота проверок</legend><div class="inline-fields"><input required type="number" min="1" max="59" name="interval_value" value="' . e((string) ($channel['interval_value'] ?? 3)) . '"><select name="interval_unit"><option value="seconds"' . selected((string) ($channel['interval_unit'] ?? 'seconds'), 'seconds') . '>секунд</option><option value="minutes"' . selected((string) ($channel['interval_unit'] ?? ''), 'minutes') . '>минут</option><option value="hours"' . selected((string) ($channel['interval_unit'] ?? ''), 'hours') . '>часов</option></select></div></fieldset>';
    echo '<label>Способ публикации <select required name="publish_mode">';
    foreach (['forward_original' => 'Переслать оригинал', 'clean_copy' => 'Без ссылок и тегов', 'text_media' => 'Текст + медиа', 'text_only' => 'Только текст', 'media_only' => 'Только медиа'] as $value => $label) {
        echo '<option value="' . e($value) . '"' . selected((string) ($channel['publish_mode'] ?? 'forward_original'), $value) . '>' . e($label) . '</option>';
    }
    echo '</select></label></div><label class="check-row"><input type="checkbox" name="only_new"' . checked((bool) ($channel['only_new'] ?? true)) . '> Брать только новые сообщения</label>';
    echo '<div class="grid-2"><label>Ключевые слова<textarea name="keywords" rows="3">' . e((string) ($channel['keywords'] ?? '')) . '</textarea></label><label>Исключающие слова<textarea name="excluded_words" rows="3">' . e((string) ($channel['excluded_words'] ?? '')) . '</textarea></label></div>';
    echo '<div class="editor-block"><label>Дополнительный текст</label><div class="rich-editor" contenteditable="true" data-editor>' . (string) ($channel['footer_html'] ?? '') . '</div><input type="hidden" name="footer_html" data-editor-input></div>';
    echo '<input type="hidden" name="active" value="' . ($active ? '1' : '0') . '"><div class="form-actions"><button class="button primary" type="submit">Сохранить</button>';
    if (!$isNew) {
        echo '<button class="button ghost" type="submit" formaction="/' . e($section) . '/channels/test">Проверить публикацию</button><button class="button danger" type="button" data-delete-form="delete-' . e($id) . '">Удалить</button>';
    }
    echo '</div></form>';
    if (!empty($channel['last_error'])) {
        echo '<div class="notice error">Последняя ошибка: ' . e((string) $channel['last_error']) . '</div>';
    }
    if (!$isNew) {
        echo '<form id="delete-' . e($id) . '" method="post" action="/' . e($section) . '/channels/delete" hidden><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="id" value="' . e($id) . '"></form>';
    }
    echo '</div></article>';
}

function renderChannelsPage(string $section, array $data): void
{
    $channels = $data[$section]['channels'] ?? [];
    $accounts = $data['accounts'] ?? [];
    renderLayoutStart(($section === 'news' ? 'Новости' : 'Воздушная тревога') . ' — Каналы данных', '/' . $section . '/channels');
    echo '<div class="section-header"><div><h2>Каналы данных</h2><p>Каналы: ' . count($channels) . ' из ' . MAX_CHANNELS . '</p></div><button class="button primary" type="button" id="showNewChannel"' . (count($channels) >= MAX_CHANNELS ? ' disabled' : '') . '>Добавить канал</button></div>';
    if (!$accounts) {
        echo '<div class="notice warning">Сначала добавьте технический аккаунт.</div>';
    }
    if (count($channels) < MAX_CHANNELS) {
        echo '<div id="newChannelWrap" class="hidden">';
        renderChannelForm($section, ['active' => true, 'only_new' => true], $accounts, true);
        echo '</div>';
    }
    echo '<div class="accordion-list">';
    foreach ($channels as $channel) {
        renderChannelForm($section, $channel, $accounts);
    }
    if (!$channels) {
        echo '<div class="empty-state"><div>＋</div><h3>Каналов пока нет</h3></div>';
    }
    echo '</div>';
    renderLayoutEnd();
}

function renderAccountForm(array $account, bool $isNew = false): void
{
    $id = (string) ($account['id'] ?? 'new');
    $active = (bool) ($account['active'] ?? true);
    $connected = (bool) ($account['connected'] ?? false);
    echo '<article class="accordion-card' . ($isNew ? ' open' : '') . '" data-accordion><div class="accordion-header"><button type="button" class="accordion-trigger"><span><strong>' . e((string) ($account['name'] ?? 'Новый аккаунт')) . '</strong><small>' . ($connected ? 'Telegram подключён' : 'Telegram не подключён') . '</small></span><span class="chevron">⌄</span></button>';
    echo '<label class="switch"><input class="account-toggle" type="checkbox" data-id="' . e($id) . '"' . checked($active) . '><span></span></label><button type="button" class="icon-button edit-trigger">✎</button></div><div class="accordion-panel">';
    echo '<form method="post" action="/group/accounts/save" class="form-grid"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="id" value="' . e($id === 'new' ? '' : $id) . '">';
    echo '<div class="grid-2"><label>Название <input required name="name" value="' . e((string) ($account['name'] ?? '')) . '"></label><label>Телефон <input required name="phone" value="' . e((string) ($account['phone'] ?? '')) . '"></label><label>API ID <input required name="api_id" value="' . e((string) ($account['api_id'] ?? '')) . '"></label><label>API Hash <input ' . ($isNew ? 'required ' : '') . 'type="password" name="api_hash" placeholder="' . ($isNew ? '' : 'Оставьте пустым, чтобы не менять') . '"></label></div>';
    echo '<input type="hidden" name="active" value="' . ($active ? '1' : '0') . '"><div class="form-actions"><button class="button primary" type="submit">Сохранить</button>';
    if (!$isNew) {
        echo '<a class="button ghost" href="/group/accounts/' . e($id) . '/telegram">' . ($connected ? 'Переподключить Telegram' : 'Подключить Telegram') . '</a><button class="button danger" type="button" data-delete-form="delete-account-' . e($id) . '">Удалить</button>';
    }
    echo '</div></form>';
    if (!$isNew) {
        echo '<form id="delete-account-' . e($id) . '" method="post" action="/group/accounts/delete" hidden><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="id" value="' . e($id) . '"></form>';
    }
    echo '</div></article>';
}

function renderGroupPage(array $data): void
{
    renderLayoutStart('Управление группой', '/group');
    echo '<div class="section-header"><div><h2>Технические аккаунты</h2><p>Аккаунты: ' . count($data['accounts']) . ' из ' . MAX_ACCOUNTS . '</p></div><button class="button primary" id="showNewAccount" type="button">Добавить аккаунт</button></div>';
    echo '<div id="newAccountWrap" class="hidden">';
    renderAccountForm(['active' => true], true);
    echo '</div><div class="accordion-list">';
    foreach ($data['accounts'] as $account) {
        renderAccountForm($account);
    }
    echo '</div>';
    renderLayoutEnd();
}

function sessionPath(string $root, string $accountId): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $accountId) ?: 'account';
    $dir = $root . '/storage/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir . '/' . $safe . '.madeline';
}

$data = loadData($storageFile);
$path = routePath();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($path === '/' && $method === 'GET') {
    renderHead('Технические работы');
    echo '<main class="maintenance-page"><section class="maintenance-card"><div class="brand-mark">SG</div><h1>SkyGuardian</h1><h2>Ведутся технические работы</h2></section></main>';
    renderFoot();
    exit;
}

if ($path === '/login' && $method === 'GET') {
    renderHead('Авторизация');
    echo '<main class="auth-page"><section class="auth-card"><h1>Вход</h1><form method="post" action="/login" class="form-grid"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><label>Email<input required type="email" name="email"></label><label>Пароль<input required type="password" name="password"></label><button class="button primary" type="submit">Войти</button></form></section></main>';
    renderFoot();
    exit;
}

if ($path === '/login' && $method === 'POST') {
    verifyCsrf();
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if (hash_equals(getenv('SG_ADMIN_EMAIL') ?: 'admin@example.com', $email) && hash_equals(getenv('SG_ADMIN_PASSWORD') ?: 'change-this-password', $password)) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        redirect('/app');
    }
    redirect('/login', 'Неверный email или пароль.', 'error');
}

if ($path === '/logout') {
    $_SESSION = [];
    session_destroy();
    redirect('/login');
}

requireAuth();

if ($path === '/app' && $method === 'GET') {
    renderLayoutStart('Главная', '/app');
    echo '<div class="blank-dashboard"></div>';
    renderLayoutEnd();
    exit;
}

if (preg_match('#^/(news|alerts)/channels$#', $path, $m) && $method === 'GET') {
    renderChannelsPage($m[1], $data);
    exit;
}

if (preg_match('#^/(news|alerts)/settings$#', $path, $m) && $method === 'GET') {
    renderLayoutStart('Настройка', '/' . $m[1] . '/settings');
    echo '<div class="empty-state large"><h2>Настройка раздела</h2></div>';
    renderLayoutEnd();
    exit;
}

if (preg_match('#^/(news|alerts)/channels/save$#', $path, $m) && $method === 'POST') {
    verifyCsrf();
    $section = $m[1];
    $postedId = trim((string) ($_POST['id'] ?? ''));
    $existing = null;
    $existingIndex = null;
    foreach ($data[$section]['channels'] as $index => $item) {
        if ((string) ($item['id'] ?? '') === $postedId) {
            $existing = $item;
            $existingIndex = $index;
            break;
        }
    }
    $channel = channelPayload($_POST, $existing);
    foreach (['name', 'source', 'account_id', 'destination', 'publish_mode'] as $required) {
        if ((string) ($channel[$required] ?? '') === '') {
            redirect('/' . $section . '/channels', 'Заполните обязательные поля.', 'error');
        }
    }
    $account = findAccount($data['accounts'], (string) $channel['account_id']);
    if ($account === null) {
        redirect('/' . $section . '/channels', 'Выбранный технический аккаунт не найден.', 'error');
    }
    if ($existingIndex !== null) {
        $data[$section]['channels'][$existingIndex] = $channel;
    } else {
        if (count($data[$section]['channels']) >= MAX_CHANNELS) {
            redirect('/' . $section . '/channels', 'Достигнут лимит каналов.', 'error');
        }
        $data[$section]['channels'][] = $channel;
    }
    saveData($storageFile, $data);
    redirect('/' . $section . '/channels', 'Канал сохранён. Выбранный технический аккаунт закреплён.');
}

if (preg_match('#^/(news|alerts)/channels/test$#', $path, $m) && $method === 'POST') {
    verifyCsrf();
    $section = $m[1];
    $id = (string) ($_POST['id'] ?? '');
    $channel = null;
    foreach ($data[$section]['channels'] as $item) {
        if ((string) ($item['id'] ?? '') === $id) {
            $channel = $item;
            break;
        }
    }
    if ($channel === null) {
        redirect('/' . $section . '/channels', 'Сначала сохраните канал.', 'error');
    }
    $account = findAccount($data['accounts'], (string) $channel['account_id']);
    if ($account === null || !(bool) ($account['active'] ?? false)) {
        redirect('/' . $section . '/channels', 'Технический аккаунт отсутствует или отключён.', 'error');
    }
    try {
        $publisher = new ChannelPublisher(sessionPath($root, (string) $account['id']), (int) $account['api_id'], (string) $account['api_hash']);
        $messages = $publisher->getNewMessages((string) $channel['source'], 0, 1);
        if (!$messages) {
            throw new RuntimeException('В источнике нет доступных сообщений.');
        }
        $publisher->publish($messages[0], (string) $channel['source'], (string) $channel['destination'], (string) $channel['publish_mode'], (string) ($channel['footer_html'] ?? ''));
        redirect('/' . $section . '/channels', 'Проверка успешна: сообщение опубликовано.');
    } catch (Throwable $error) {
        redirect('/' . $section . '/channels', 'Ошибка публикации: ' . $error->getMessage(), 'error');
    }
}

if (preg_match('#^/(news|alerts)/channels/delete$#', $path, $m) && $method === 'POST') {
    verifyCsrf();
    $id = (string) ($_POST['id'] ?? '');
    $data[$m[1]]['channels'] = array_values(array_filter($data[$m[1]]['channels'], fn(array $item): bool => (string) ($item['id'] ?? '') !== $id));
    saveData($storageFile, $data);
    redirect('/' . $m[1] . '/channels', 'Канал удалён.');
}

if (preg_match('#^/(news|alerts)/channels/toggle$#', $path, $m) && $method === 'POST') {
    verifyCsrf();
    foreach ($data[$m[1]]['channels'] as &$channel) {
        if ((string) ($channel['id'] ?? '') === (string) ($_POST['id'] ?? '')) {
            $channel['active'] = ($_POST['active'] ?? '0') === '1';
            break;
        }
    }
    unset($channel);
    saveData($storageFile, $data);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if ($path === '/group' && $method === 'GET') {
    renderGroupPage($data);
    exit;
}

if ($path === '/group/accounts/save' && $method === 'POST') {
    verifyCsrf();
    $postedId = trim((string) ($_POST['id'] ?? ''));
    $existing = null;
    $existingIndex = null;
    foreach ($data['accounts'] as $index => $item) {
        if ((string) ($item['id'] ?? '') === $postedId) {
            $existing = $item;
            $existingIndex = $index;
            break;
        }
    }
    $account = accountPayload($_POST, $existing);
    foreach (['name', 'phone', 'api_id', 'api_hash'] as $required) {
        if ((string) ($account[$required] ?? '') === '') {
            redirect('/group', 'Заполните обязательные поля.', 'error');
        }
    }
    if ($existingIndex !== null) {
        $data['accounts'][$existingIndex] = $account;
    } else {
        if (count($data['accounts']) >= MAX_ACCOUNTS) {
            redirect('/group', 'Достигнут лимит аккаунтов.', 'error');
        }
        $data['accounts'][] = $account;
    }
    saveData($storageFile, $data);
    redirect('/group', 'Технический аккаунт сохранён без сброса Telegram-сессии.');
}

if (preg_match('#^/group/accounts/([a-zA-Z0-9_-]+)/telegram$#', $path, $m) && $method === 'GET') {
    $account = findAccount($data['accounts'], $m[1]);
    if ($account === null) {
        redirect('/group', 'Аккаунт не найден.', 'error');
    }
    renderLayoutStart('Подключение Telegram', '/group');
    echo '<section class="auth-card"><h2>Сканируйте QR-код</h2><div id="telegramQr" data-account="' . e($m[1]) . '"><p>Загрузка QR-кода…</p></div><p>Telegram → Настройки → Устройства → Подключить устройство.</p><a class="button ghost" href="/group">Назад</a></section>';
    echo '<script>window.SG_TELEGRAM_ACCOUNT=' . json_encode($m[1]) . ';</script>';
    renderLayoutEnd();
    exit;
}

if (preg_match('#^/group/accounts/([a-zA-Z0-9_-]+)/telegram/qr$#', $path, $m) && $method === 'GET') {
    $account = findAccount($data['accounts'], $m[1]);
    header('Content-Type: application/json; charset=utf-8');
    if ($account === null || !class_exists(QrLoginService::class)) {
        http_response_code(404);
        echo json_encode(['error' => 'Аккаунт или Telegram-библиотека недоступны.']);
        exit;
    }
    try {
        $service = new QrLoginService(sessionPath($root, $m[1]), (int) $account['api_id'], (string) $account['api_hash']);
        $result = $service->getQrCode(isset($_GET['wait']));
        if (($result['logged_in'] ?? false) === true) {
            foreach ($data['accounts'] as &$item) {
                if ((string) ($item['id'] ?? '') === $m[1]) {
                    $item['connected'] = true;
                    $item['telegram_user'] = $service->getAccount();
                    break;
                }
            }
            unset($item);
            saveData($storageFile, $data);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($path === '/group/accounts/delete' && $method === 'POST') {
    verifyCsrf();
    $id = (string) ($_POST['id'] ?? '');
    foreach (['news', 'alerts'] as $section) {
        foreach ($data[$section]['channels'] as $channel) {
            if ((string) ($channel['account_id'] ?? '') === $id) {
                redirect('/group', 'Аккаунт используется каналом и не может быть удалён.', 'error');
            }
        }
    }
    $data['accounts'] = array_values(array_filter($data['accounts'], fn(array $item): bool => (string) ($item['id'] ?? '') !== $id));
    saveData($storageFile, $data);
    redirect('/group', 'Аккаунт удалён.');
}

if ($path === '/group/accounts/toggle' && $method === 'POST') {
    verifyCsrf();
    foreach ($data['accounts'] as &$account) {
        if ((string) ($account['id'] ?? '') === (string) ($_POST['id'] ?? '')) {
            $account['active'] = ($_POST['active'] ?? '0') === '1';
            break;
        }
    }
    unset($account);
    saveData($storageFile, $data);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(404);
renderError(404, 'Страница не найдена', 'Проверьте адрес.');
