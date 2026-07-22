<?php
declare(strict_types=1);

namespace SkyGuardian\Telegram;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;

final class QrLoginService
{
    public function __construct(private readonly string $projectRoot) {}

    public function qr(array $account, bool $wait = false): array
    {
        $api = $this->api($account);
        try {
            $qr = $api->qrLogin();
            if ($wait && $qr !== null) {
                try {
                    $qr = $qr->waitForLoginOrQrCodeExpiration(new TimeoutCancellation(5.0));
                } catch (CancelledException) {
                    $qr = $api->qrLogin();
                }
            }

            if ($qr !== null) {
                return [
                    'logged_in' => false,
                    'needs_2fa' => false,
                    'svg' => $qr->getQRSvg(360, 3),
                    'expires_in' => $qr->expiresIn(),
                ];
            }

            $needs2fa = $api->getAuthorization() === API::WAITING_PASSWORD;
            return [
                'logged_in' => !$needs2fa,
                'needs_2fa' => $needs2fa,
                'user' => $needs2fa ? null : $this->user($api),
            ];
        } finally {
            unset($api);
        }
    }

    public function complete2fa(array $account, string $password): array
    {
        if ($password === '') {
            throw new \InvalidArgumentException('Введите пароль двухэтапной аутентификации.');
        }
        $api = $this->api($account);
        try {
            $api->complete2faLogin($password);
            return ['logged_in' => true, 'needs_2fa' => false, 'user' => $this->user($api)];
        } finally {
            unset($api);
        }
    }

    private function api(array $account): API
    {
        $apiId = (int) ($account['api_id'] ?? 0);
        $apiHash = trim((string) ($account['api_hash'] ?? ''));
        $sessionPath = trim((string) ($account['session_path'] ?? ''));
        if ($apiId <= 0 || $apiHash === '' || $sessionPath === '') {
            throw new \InvalidArgumentException('Telegram API ID, API Hash или путь сессии не настроены.');
        }
        $absolute = str_starts_with($sessionPath, '/')
            ? $sessionPath
            : rtrim($this->projectRoot, '/') . '/' . ltrim($sessionPath, '/');
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать каталог Telegram-сессии.');
        }
        $settings = (new AppInfo())->setApiId($apiId)->setApiHash($apiHash)->setShowPrompt(false);
        return new API($absolute, $settings);
    }

    private function user(API $api): array
    {
        $self = $api->getSelf();
        if (!is_array($self)) {
            throw new \RuntimeException('Telegram не вернул данные подключённого пользователя.');
        }
        return [
            'id' => $self['id'] ?? null,
            'username' => $self['username'] ?? null,
            'first_name' => $self['first_name'] ?? null,
            'last_name' => $self['last_name'] ?? null,
            'phone' => $self['phone'] ?? null,
        ];
    }
}
