from pathlib import Path

index = Path('public/index.php')
text = index.read_text()

old_upload = """    if (in_array($kind, ['photo', 'video', 'document'], true)) {
        $file = $_FILES['media'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $reply(422, ['ok' => false, 'message' => 'Выберите файл для отправки.']);
        }
        if ((int) $file['size'] > 49 * 1024 * 1024) $reply(422, ['ok' => false, 'message' => 'Файл превышает лимит 49 МБ.']);
        $method = ['photo' => 'sendPhoto', 'video' => 'sendVideo', 'document' => 'sendDocument'][$kind];
        $parameters[$kind] = new CURLFile((string) $file['tmp_name'], (string) ($file['type'] ?: 'application/octet-stream'), basename((string) $file['name']));
        if ($text !== '') $parameters['caption'] = $text;
    }
"""
new_upload = """    if (in_array($kind, ['photo', 'video', 'document'], true)) {
        $file = $_FILES['media'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $reply(422, ['ok' => false, 'message' => 'Выберите файл для отправки.']);
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > 49 * 1024 * 1024) {
            $reply(422, ['ok' => false, 'message' => 'Файл пустой или превышает лимит 49 МБ.']);
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->file((string) $file['tmp_name']);
        $allowedMime = [
            'photo' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'video' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska'],
        ];
        if (isset($allowedMime[$kind]) && !in_array($detectedMime, $allowedMime[$kind], true)) {
            $reply(422, ['ok' => false, 'message' => 'Содержимое файла не соответствует выбранному типу публикации.']);
        }
        if ($kind === 'document' && in_array($detectedMime, [
            'text/x-php', 'application/x-httpd-php', 'application/x-php',
            'application/x-executable', 'application/x-dosexec', 'application/x-sharedlib',
        ], true)) {
            $reply(422, ['ok' => false, 'message' => 'Этот тип документа запрещён.']);
        }
        $safeName = preg_replace('/[\\x00-\\x1F\\x7F\\\\\/]+/u', '_', basename((string) $file['name'])) ?: 'upload';
        $safeName = mb_substr($safeName, 0, 180);
        $method = ['photo' => 'sendPhoto', 'video' => 'sendVideo', 'document' => 'sendDocument'][$kind];
        $parameters[$kind] = new CURLFile((string) $file['tmp_name'], $detectedMime !== '' ? $detectedMime : 'application/octet-stream', $safeName);
        if ($text !== '') $parameters['caption'] = $text;
    }
"""
if old_upload not in text:
    raise SystemExit('upload block not found')
text = text.replace(old_upload, new_upload, 1)

old_logout = """if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    header('Location: /?page=login');
    exit;
}
"""
new_logout = """if ($action === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit;
    }
    if (!$isAuthenticated || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
        http_response_code(419);
        exit;
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
    header('Location: /?page=login');
    exit;
}
"""
if old_logout not in text:
    raise SystemExit('logout block not found')
text = text.replace(old_logout, new_logout, 1)

old_link = '            <a class="nav-link logout" href="/?action=logout"><span class="nav-icon">↪</span><span>Выйти</span></a>'
new_link = """            <form method="post" action="/?action=logout" class="logout-form">
                <input type="hidden" name="_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <button class="nav-link logout" type="submit"><span class="nav-icon">↪</span><span>Выйти</span></button>
            </form>"""
if old_link not in text:
    raise SystemExit('logout link not found')
text = text.replace(old_link, new_link, 1)

replacements = {
"""    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
        if (str_contains(strtolower($message), 'bad webhook')) $message = 'Telegram не принял webhook. Проверьте HTTPS и публичный адрес сайта.';
        $reply(422, ['ok' => false, 'message' => $message]);
    } catch (Throwable $exception) {
        error_log('Telegram automation error: ' . $exception::class . ': ' . $exception->getMessage());
        $message = $exception->getMessage();
        if ($message === '') {
            $message = 'Не удалось сохранить автоматизацию.';
        }
        $reply(503, ['ok' => false, 'message' => $message]);
    }
""": """    } catch (RuntimeException $exception) {
        error_log('Telegram automation runtime error: ' . $exception->getMessage());
        $message = str_contains(strtolower($exception->getMessage()), 'bad webhook')
            ? 'Telegram не принял webhook. Проверьте HTTPS и публичный адрес сайта.'
            : 'Telegram отклонил настройки автоматизации.';
        $reply(422, ['ok' => false, 'message' => $message]);
    } catch (Throwable $exception) {
        error_log('Telegram automation error: ' . $exception::class . ': ' . $exception->getMessage());
        $reply(503, ['ok' => false, 'message' => 'Не удалось сохранить автоматизацию.']);
    }
""",
"""    } catch (RuntimeException $exception) {
        $reply(422, ['ok' => false, 'message' => $exception->getMessage()]);
    } catch (Throwable) {
        $reply(503, ['ok' => false, 'message' => 'Не удалось выполнить команду Telegram.']);
    }
""": """    } catch (RuntimeException $exception) {
        error_log('Telegram manage error: ' . $exception->getMessage());
        $reply(422, ['ok' => false, 'message' => 'Telegram отклонил команду. Проверьте права бота и параметры.']);
    } catch (Throwable $exception) {
        error_log('Telegram manage failure: ' . $exception::class . ': ' . $exception->getMessage());
        $reply(503, ['ok' => false, 'message' => 'Не удалось выполнить команду Telegram.']);
    }
""",
"""    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
        if (str_contains(strtolower($message), 'not enough rights')) $message = 'У бота недостаточно прав для этого действия.';
        $reply(422, ['ok' => false, 'message' => $message]);
    } catch (Throwable) {
        $reply(503, ['ok' => false, 'message' => 'Не удалось отправить публикацию.']);
    }
""": """    } catch (RuntimeException $exception) {
        error_log('Telegram publish error: ' . $exception->getMessage());
        $message = str_contains(strtolower($exception->getMessage()), 'not enough rights')
            ? 'У бота недостаточно прав для этого действия.'
            : 'Telegram отклонил публикацию. Проверьте файл, текст и права бота.';
        $reply(422, ['ok' => false, 'message' => $message]);
    } catch (Throwable $exception) {
        error_log('Telegram publish failure: ' . $exception::class . ': ' . $exception->getMessage());
        $reply(503, ['ok' => false, 'message' => 'Не удалось отправить публикацию.']);
    }
""",
"""        $reply(422, ['ok' => false, 'message' => $message]);
    } catch (Throwable) {
        $reply(503, ['ok' => false, 'message' => 'Не удалось проверить подключение Telegram.']);
    }
""": """        if (!str_contains(strtolower($message), 'unauthorized') && !str_contains(strtolower($message), 'chat not found')) {
            error_log('Telegram check error: ' . $message);
            $message = 'Telegram отклонил проверку подключения.';
        }
        $reply(422, ['ok' => false, 'message' => $message]);
    } catch (Throwable $exception) {
        error_log('Telegram check failure: ' . $exception::class . ': ' . $exception->getMessage());
        $reply(503, ['ok' => false, 'message' => 'Не удалось проверить подключение Telegram.']);
    }
""",
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit('expected Telegram error block not found')
    text = text.replace(old, new, 1)

index.write_text(text)

session_test = Path('tests/session-security.php')
test = session_test.read_text()
old_test = """    $logout = $request('GET', $baseUrl . '/?action=logout', [], $cookieJar);
    if ($logout['status'] !== 302) $fail('logout did not redirect');
"""
new_test = """    if (!preg_match('/<form method="post" action="\\/\\?action=logout"[^>]*>.*?name="_token" value="([a-f0-9]{64})"/s', $dashboard['body'], $logoutMatch)) {
        $fail('logout CSRF form missing');
    }
    $logoutGet = $request('GET', $baseUrl . '/?action=logout', [], $cookieJar);
    if ($logoutGet['status'] !== 405) $fail('GET logout was not rejected');
    $logout = $request('POST', $baseUrl . '/?action=logout', ['_token' => $logoutMatch[1]], $cookieJar);
    if ($logout['status'] !== 302) $fail('POST logout did not redirect');
"""
if old_test not in test:
    raise SystemExit('session logout test block not found')
session_test.write_text(test.replace(old_test, new_test, 1))
