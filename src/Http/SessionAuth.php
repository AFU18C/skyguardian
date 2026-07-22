<?php
declare(strict_types=1);

namespace SkyGuardian\Http;

use SkyGuardian\Auth\AuthService;

final class SessionAuth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name('skyguardian_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function attempt(AuthService $auth, string $email, string $password): bool
    {
        self::start();
        $now = time();
        $attempts = (array) ($_SESSION['login_attempts'] ?? []);
        $attempts = array_values(array_filter($attempts, static fn(int $time): bool => $time > $now - 900));
        if (count($attempts) >= 5) return false;
        if (!$auth->verify($email, $password)) {
            $attempts[] = $now;
            $_SESSION['login_attempts'] = $attempts;
            usleep(400000);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION = ['authenticated' => true, 'email' => mb_strtolower(trim($email)), 'logged_in_at' => $now];
        return true;
    }

    public static function check(): bool
    {
        self::start();
        return ($_SESSION['authenticated'] ?? false) === true;
    }

    public static function requireLogin(): void
    {
        if (self::check()) return;
        header('Location: /v1/admin/login.php', true, 302);
        exit;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }
}
