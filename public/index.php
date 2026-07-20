<?php

declare(strict_types=1);

session_start();

const APP_NAME = 'SkyGuardian';
const MAX_CHANNELS = 10;
const MAX_ACCOUNTS = 10;

$root = dirname(__DIR__);
$storageFile = $root . '/storage/skyguardian.json';

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
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
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
    return $_SESSION['csrf'];
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

function redirect(string $path, ?string $message = null): never
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
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
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' — ' . APP_NAME . '</title>';
    echo '<link rel="stylesheet" href="/assets/app.css"></head><body>';
}

function renderFoot(): void
{
    echo '<script src="/assets/app.js"></script></body></html>';
}

function renderError(int $code, string $title, string $text): void
{
    renderHead((string) $code);
    echo '<main class="state-page"><section class="state-card">';
    echo '<div class="state-code">' . $code . '</div><h1>' . e($title) . '</h1><p>' . e($text) . '</p>';
    echo '<a class="button primary" href="/">На главную</a></section></main>';
    renderFoot();
}

function renderPublicHome(): void
{
    renderHead('Технические работы');
    echo '<main class="maintenance-page"><section class="maintenance-card">';
    echo '<div class="brand-mark">SG</div><h1>SkyGuardian</h1>';
    echo '<h2>Ведутся технические работы</h2>';
    echo '<p>Сервис находится в разработке. Пожалуйста, зайдите позже.</p>';
    echo '</section></main>';
    renderFoot();
}

function renderLogin(?string $error = null): void
{
    renderHead('Авторизация');
    echo '<main class="auth-page"><section class="auth-card">';
    echo '<div class="brand-line"><div class="brand-mark small">SG</div><div><strong>SkyGuardian</strong><span>Панель управления</span></div></div>';
    echo '<h1>Вход</h1>';
    if ($error) {
        echo '<div class="notice error">' . e($error) . '</div>';
    }
    echo '<form method="post" action="/login" class="form-grid">';
    echo '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
    echo '<label>Email<input required type="email" name="email" autocomplete="username"></label>';
    echo '<label>Пароль<input required type="password" name="password" autocomplete="current-password"></label>';
    echo '<button class="button primary full" type="submit">Войти</button>';
    echo '</form></section></main>';
    renderFoot();
}

function navLink(string $href, string $label, string $active): string
{
    $class = $active === $href ? 'nav-link active' : 'nav-link';
    return '<a class="' . $class . '" href="' . $href . '">' . e($label) . '</a>';
}

function renderLayoutStart(string $title, string $active): void
{
    renderHead($title);
    echo '<div class="app-shell">';
    echo '<aside class="sidebar" id="sidebar">';
    echo '<div class="sidebar-brand"><div class="brand-mark small">SG</div><strong>SkyGuardian</strong></div>';
    echo '<nav>';
    echo navLink('/app', 'Главная', $active);
    echo '<div class="nav-heading">НОВОСТИ</div>';
    echo navLink('/news/channels', 'Каналы данных', $active);
    echo navLink('/news/settings', 'Настройка', $active);
    echo '<div class="nav-heading">ВОЗДУШНАЯ ТРЕВОГА</div>';
    echo navLink('/alerts/channels', 'Каналы данных', $active);
    echo navLink('/alerts/settings', 'Настройка', $active);
    echo '<div class="nav-heading">ОБЩИЕ НАСТРОЙКИ</div>';
    echo navLink('/group', 'Управление группой', $active);
    echo '<a class="nav-link logout" href="/logout">Выйти</a>';
    echo '</nav></aside>';
    echo '<div class="main-column"><header class="topbar"><button id="menuToggle" class="icon-button" type="button" aria-label="Открыть меню">☰</button><h1>' . e($title) . '</h1></header>';
    echo '<main class="content">';
    if (!empty($_SESSION['flash'])) {
        echo '<div class="notice success" data-autohide>' . e((string) $_SESSION['flash']) . '</div>';
        unset($_SESSION['flash']);
    }
}

function renderLayoutEnd(): void
{
    echo '</main></div></div><div class="sidebar-overlay" id="sidebarOverlay"></div>';
    echo '<dialog id="confirmDialog" class="modal"><div class="modal-body"><h2>Подтвердите удаление</h2><p>Настройки будут удалены без возможности восстановления.</p><div class="modal-actions"><button class="button ghost" type="button" data-close-dialog>Отмена</button><button class="button danger" type="button" id="confirmDelete">Удалить</button></div></div></dialog>';
    renderFoot();
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

function channelPayload(array $input): array
{
    [$intervalValue, $intervalUnit] = normalizeInterval((int) ($input['interval_value'] ?? 3), (string) ($input['interval_unit'] ?? 'seconds'));

    return [
        'id' => trim((string) ($input['id'] ?? '')) ?: bin2hex(random_bytes(8)),
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
        'active' => !isset($input['active']) || $input['active'] === '1',
    ];
}

function validateChannel(array $channel): array
{
    $required = ['name', 'source', 'account_id', 'destination', 'publish_mode'];
    $errors = [];
    foreach ($required as $field) {
        if (($channel[$field] ?? '') === '') {
            $errors[] = $field;
        }
    }
    return $errors;
}

function renderChannelForm(string $section, array $channel, array $accounts, bool $isNew = false): void
{
    $id = (string) ($channel['id'] ?? 'new');
    $active = (bool) ($channel['active'] ?? true);
    $name = (string) ($channel['name'] ?? 'Новый канал');
    $source = (string) ($channel['source'] ?? '');

    echo '<article class="accordion-card' . ($isNew ? ' open' : '') . '" data-accordion>';
    echo '<div class="accordion-header">';
    echo '<button type="button" class="accordion-trigger" aria-expanded="' . ($isNew ? 'true' : 'false') . '"><span><strong>' . e($name) . '</strong><small>' . e($source ?: 'Источник не указан') . '</small></span><span class="chevron">⌄</span></button>';
    echo '<label class="switch" title="Включить или выключить"><input class="channel-toggle" type="checkbox" data-section="' . e($section) . '" data-id="' . e($id) . '"' . checked($active) . '><span></span></label>';
    echo '<button type="button" class="icon-button edit-trigger" aria-label="Редактировать">✎</button>';
    echo '</div>';
    echo '<div class="accordion-panel">';
    echo '<form method="post" action="/' . e($section) . '/channels/save" class="channel-form form-grid">';
    echo '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="id" value="' . e($id === 'new' ? '' : $id) . '">';
    echo '<div class="grid-2">';
    echo '<label>Название канала <span class="required">*</span><input required name="name" value="' . e((string) ($channel['name'] ?? '')) . '"></label>';
    echo '<label>Источник: ссылка или @username <span class="required">*</span><input required name="source" placeholder="https://t.me/channel или @channel" value="' . e($source) . '"></label>';
    echo '<label>Технический аккаунт <span class="required">*</span><select required name="account_id"><option value="">Выберите аккаунт</option>';
    foreach ($accounts as $account) {
        echo '<option value="' . e((string) $account['id']) . '"' . selected((string) ($channel['account_id'] ?? ''), (string) $account['id']) . '>' . e((string) $account['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Канал или группа для публикации <span class="required">*</span><input required name="destination" placeholder="https://t.me/group или @group" value="' . e((string) ($channel['destination'] ?? '')) . '"></label>';
    echo '</div>';
    echo '<div class="grid-2">';
    echo '<fieldset><legend>Частота проверок <span class="required">*</span></legend><div class="inline-fields"><input required min="1" max="59" type="number" name="interval_value" value="' . e((string) ($channel['interval_value'] ?? 3)) . '"><select name="interval_unit"><option value="seconds"' . selected((string) ($channel['interval_unit'] ?? 'seconds'), 'seconds') . '>секунд</option><option value="minutes"' . selected((string) ($channel['interval_unit'] ?? ''), 'minutes') . '>минут</option><option value="hours"' . selected((string) ($channel['interval_unit'] ?? ''), 'hours') . '>часов</option></select></div><small>Допустимый диапазон: от 3 секунд до 12 часов.</small></fieldset>';
    echo '<label>Способ публикации <span class="required">*</span><select required name="publish_mode">';
    $modes = ['forward_original' => 'Переслать оригинал', 'clean_copy' => 'Опубликовать без ссылок и тегов', 'text_media' => 'Текст + медиа', 'text_only' => 'Только текст', 'media_only' => 'Только медиа'];
    foreach ($modes as $value => $label) {
        echo '<option value="' . e($value) . '"' . selected((string) ($channel['publish_mode'] ?? 'forward_original'), $value) . '>' . e($label) . '</option>';
    }
    echo '</select></label></div>';
    echo '<label class="check-row"><input type="checkbox" name="only_new"' . checked((bool) ($channel['only_new'] ?? true)) . '> Брать только новые сообщения</label>';
    echo '<div class="grid-2"><label>Фильтр по ключевым словам<textarea name="keywords" rows="3" placeholder="Слова или фразы через запятую">' . e((string) ($channel['keywords'] ?? '')) . '</textarea></label><label>Исключающие слова<textarea name="excluded_words" rows="3" placeholder="Слова или фразы через запятую">' . e((string) ($channel['excluded_words'] ?? '')) . '</textarea></label></div>';
    echo '<div class="editor-block"><label>Дополнительный текст</label><div class="editor-toolbar"><button type="button" data-command="bold"><b>B</b></button><button type="button" data-command="italic"><i>I</i></button><button type="button" data-command="underline"><u>U</u></button><button type="button" data-command="strikeThrough"><s>S</s></button><button type="button" data-command="insertUnorderedList">• Список</button><button type="button" data-command="createLink">Ссылка</button></div><div class="rich-editor" contenteditable="true" data-editor>' . (string) ($channel['footer_html'] ?? '') . '</div><input type="hidden" name="footer_html" data-editor-input><small>Этот текст будет добавлен внизу опубликованного сообщения.</small></div>';
    echo '<input type="hidden" name="active" value="' . ($active ? '1' : '0') . '">';
    echo '<div class="form-actions"><button class="button primary" type="submit">Сохранить</button>';
    if (!$isNew) {
        echo '<button class="button danger" type="button" data-delete-form="delete-' . e($id) . '">Удалить</button>';
    }
    echo '</div></form>';
    if (!$isNew) {
        echo '<form id="delete-' . e($id) . '" method="post" action="/' . e($section) . '/channels/delete" hidden><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="id" value="' . e($id) . '"></form>';
    }
    echo '</div></article>';
}

function renderChannelsPage(string $section, array $data): void
{
    $sectionTitle = $section === 'news' ? 'Новости' : 'Воздушная тревога';
    $channels = $data[$section]['channels'];
    $accounts = array_values(array_filter($data['accounts'], fn(array $account): bool => (bool) ($account['active'] ?? false)));
    renderLayoutStart($sectionTitle . ' — Каналы данных', '/' . $section . '/channels');
    echo '<div class="section-header"><div><h2>Каналы данных</h2><p>Каналы: ' . count($channels) . ' из ' . MAX_CHANNELS . '</p></div>';
    if (count($channels) < MAX_CHANNELS) {
        echo '<button class="button primary" type="button" id="showNewChannel">Добавить канал</button>';
    } else {
        echo '<button class="button primary" type="button" disabled>Добавить канал</button>';
    }
    echo '</div>';
    if (!$accounts) {
        echo '<div class="notice warning">Сначала добавьте и включите технический аккаунт в разделе «Управление группой».</div>';
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
        echo '<div class="empty-state"><div>＋</div><h3>Каналов пока нет</h3><p>Добавьте первый канал данных.</p></div>';
    }
    echo '</div>';
    renderLayoutEnd();
}

function renderSettingsPage(string $section): void
{
    $title = $section === 'news' ? 'Новости — Настройка' : 'Воздушная тревога — Настройка';
    renderLayoutStart($title, '/' . $section . '/settings');
    echo '<div class="empty-state large"><div>⚙</div><h2>Настройка раздела</h2><p>Страница подготовлена. Настройки разделов будут храниться отдельно.</p></div>';
    renderLayoutEnd();
}

function accountPayload(array $input): array
{
    return [
        'id' => trim((string) ($input['id'] ?? '')) ?: bin2hex(random_bytes(8)),
        'name' => trim((string) ($input['name'] ?? '')),
        'phone' => trim((string) ($input['phone'] ?? '')),
        'api_id' => trim((string) ($input['api_id'] ?? '')),
        'api_hash' => trim((string) ($input['api_hash'] ?? '')),
        'active' => !isset($input['active']) || $input['active'] === '1',
        'connected' => false,
    ];
}

function renderAccountForm(array $account, bool $isNew = false): void
{
    $id = (string) ($account['id'] ?? 'new');
    $active = (bool) ($account['active'] ?? true);
    echo '<article class="accordion-card' . ($isNew ? ' open' : '') . '" data-accordion><div class="accordion-header">';
    echo '<button type="button" class="accordion-trigger" aria-expanded="' . ($isNew ? 'true' : 'false') . '"><span><strong>' . e((string) ($account['name'] ?? 'Новый аккаунт')) . '</strong><small>' . e((string) ($account['phone'] ?? 'Телефон не указан')) . '</small></span><span class="chevron">⌄</span></button>';
    echo '<label class="switch"><input class="account-toggle" type="checkbox" data-id="' . e($id) . '"' . checked($active) . '><span></span></label><button type="button" class="icon-button edit-trigger">✎</button></div>';
    echo '<div class="accordion-panel"><form method="post" action="/group/accounts/save" class="form-grid">';
    echo '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="id" value="' . e($id === 'new' ? '' : $id) . '">';
    echo '<div class="grid-2"><label>Название <span class="required">*</span><input required name="name" value="' . e((string) ($account['name'] ?? '')) . '"></label><label>Номер телефона <span class="required">*</span><input required name="phone" value="' . e((string) ($account['phone'] ?? '')) . '"></label><label>API ID <span class="required">*</span><input required name="api_id" value="' . e((string) ($account['api_id'] ?? '')) . '"></label><label>API Hash <span class="required">*</span><input required type="password" name="api_hash" value="' . e((string) ($account['api_hash'] ?? '')) . '"></label></div>';
    echo '<div class="qr-box"><div><strong>Telegram-сессия</strong><p>Подключение по QR-коду будет выполняться через отдельное защищённое окно.</p></div><button class="button ghost" type="button" disabled>Подключить по QR-коду</button></div>';
    echo '<input type="hidden" name="active" value="' . ($active ? '1' : '0') . '"><div class="form-actions"><button class="button primary" type="submit">Сохранить</button>';
    if (!$isNew) {
        echo '<button class="button danger" type="button" data-delete-form="delete-account-' . e($id) . '">Удалить</button>';
    }
    echo '</div></form>';
    if (!$isNew) {
        echo '<form id="delete-account-' . e($id) . '" method="post" action="/group/accounts/delete" hidden><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="id" value="' . e($id) . '"></form>';
    }
    echo '</div></article>';
}

function renderGroupPage(array $data): void
{
    $accounts = $data['accounts'];
    renderLayoutStart('Управление группой', '/group');
    echo '<div class="section-header"><div><h2>Технические аккаунты</h2><p>Технические аккаунты: ' . count($accounts) . ' из ' . MAX_ACCOUNTS . '</p></div>';
    if (count($accounts) < MAX_ACCOUNTS) {
        echo '<button class="button primary" type="button" id="showNewAccount">Добавить аккаунт</button>';
    } else {
        echo '<button class="button primary" disabled type="button">Добавить аккаунт</button>';
    }
    echo '</div>';
    if (count($accounts) < MAX_ACCOUNTS) {
        echo '<div id="newAccountWrap" class="hidden">';
        renderAccountForm(['active' => true], true);
        echo '</div>';
    }
    echo '<div class="accordion-list">';
    foreach ($accounts as $account) {
        renderAccountForm($account);
    }
    if (!$accounts) {
        echo '<div class="empty-state"><div>＋</div><h3>Аккаунтов пока нет</h3><p>Добавьте первый технический аккаунт.</p></div>';
    }
    echo '</div>';
    renderLayoutEnd();
}

$data = loadData($storageFile);
$path = routePath();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($path === '/' && $method === 'GET') {
    renderPublicHome();
    exit;
}

if ($path === '/login' && $method === 'GET') {
    if (isAuthenticated()) {
        redirect('/app');
    }
    renderLogin();
    exit;
}

if ($path === '/login' && $method === 'POST') {
    verifyCsrf();
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $expectedEmail = getenv('SG_ADMIN_EMAIL') ?: 'admin@example.com';
    $expectedPassword = getenv('SG_ADMIN_PASSWORD') ?: 'change-this-password';
    if (hash_equals($expectedEmail, $email) && hash_equals($expectedPassword, $password)) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        redirect('/app');
    }
    renderLogin('Неверный email или пароль.');
    exit;
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

if (preg_match('#^/(news|alerts)/channels$#', $path, $match) && $method === 'GET') {
    renderChannelsPage($match[1], $data);
    exit;
}

if (preg_match('#^/(news|alerts)/settings$#', $path, $match) && $method === 'GET') {
    renderSettingsPage($match[1]);
    exit;
}

if (preg_match('#^/(news|alerts)/channels/save$#', $path, $match) && $method === 'POST') {
    verifyCsrf();
    $section = $match[1];
    $channel = channelPayload($_POST);
    if (validateChannel($channel)) {
        redirect('/' . $section . '/channels', 'Заполните обязательные поля.');
    }
    $found = false;
    foreach ($data[$section]['channels'] as $index => $existing) {
        if ($existing['id'] === $channel['id']) {
            $data[$section]['channels'][$index] = $channel;
            $found = true;
            break;
        }
    }
    if (!$found) {
        if (count($data[$section]['channels']) >= MAX_CHANNELS) {
            redirect('/' . $section . '/channels', 'Достигнут лимит каналов.');
        }
        $data[$section]['channels'][] = $channel;
    }
    saveData($storageFile, $data);
    redirect('/' . $section . '/channels', 'Канал сохранён.');
}

if (preg_match('#^/(news|alerts)/channels/delete$#', $path, $match) && $method === 'POST') {
    verifyCsrf();
    $section = $match[1];
    $id = (string) ($_POST['id'] ?? '');
    $data[$section]['channels'] = array_values(array_filter($data[$section]['channels'], fn(array $channel): bool => $channel['id'] !== $id));
    saveData($storageFile, $data);
    redirect('/' . $section . '/channels', 'Канал удалён.');
}

if (preg_match('#^/(news|alerts)/channels/toggle$#', $path, $match) && $method === 'POST') {
    verifyCsrf();
    $section = $match[1];
    $id = (string) ($_POST['id'] ?? '');
    $active = ($_POST['active'] ?? '0') === '1';
    foreach ($data[$section]['channels'] as &$channel) {
        if ($channel['id'] === $id) {
            $channel['active'] = $active;
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
    $account = accountPayload($_POST);
    if ($account['name'] === '' || $account['phone'] === '' || $account['api_id'] === '' || $account['api_hash'] === '') {
        redirect('/group', 'Заполните обязательные поля.');
    }
    $found = false;
    foreach ($data['accounts'] as $index => $existing) {
        if ($existing['id'] === $account['id']) {
            $data['accounts'][$index] = $account;
            $found = true;
            break;
        }
    }
    if (!$found) {
        if (count($data['accounts']) >= MAX_ACCOUNTS) {
            redirect('/group', 'Достигнут лимит технических аккаунтов.');
        }
        $data['accounts'][] = $account;
    }
    saveData($storageFile, $data);
    redirect('/group', 'Технический аккаунт сохранён.');
}

if ($path === '/group/accounts/delete' && $method === 'POST') {
    verifyCsrf();
    $id = (string) ($_POST['id'] ?? '');
    $data['accounts'] = array_values(array_filter($data['accounts'], fn(array $account): bool => $account['id'] !== $id));
    saveData($storageFile, $data);
    redirect('/group', 'Технический аккаунт удалён.');
}

if ($path === '/group/accounts/toggle' && $method === 'POST') {
    verifyCsrf();
    $id = (string) ($_POST['id'] ?? '');
    $active = ($_POST['active'] ?? '0') === '1';
    foreach ($data['accounts'] as &$account) {
        if ($account['id'] === $id) {
            $account['active'] = $active;
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
renderError(404, 'Страница не найдена', 'Проверьте адрес или вернитесь на главную страницу.');
