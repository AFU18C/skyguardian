<?php

declare(strict_types=1);

namespace SkyGuardian\Telegram;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use danog\MadelineProto\API;
use danog\MadelineProto\Settings;

final class QrLoginService
{
    private API $client;

    public function __construct(
        string $sessionPath,
        int $apiId,
        string $apiHash,
    ) {
        $settings = new Settings();
        $settings->getAppInfo()
            ->setApiId($apiId)
            ->setApiHash($apiHash);

        $this->client = new API($sessionPath, $settings);
    }

    /**
     * @return array{logged_in: bool, needs_2fa: bool, svg?: string}
     */
    public function getQrCode(bool $wait = false): array
    {
        try {
            $qr = $this->client->qrLogin();
            if ($wait) {
                $qr = $qr?->waitForLoginOrQrCodeExpiration(new TimeoutCancellation(5.0));
            }
        } catch (CancelledException) {
            $qr = $this->client->qrLogin();
        }

        if ($qr !== null) {
            return [
                'logged_in' => false,
                'needs_2fa' => false,
                'svg' => $qr->getQRSvg(400, 2),
            ];
        }

        return [
            'logged_in' => true,
            'needs_2fa' => $this->client->getAuthorization() === API::WAITING_PASSWORD,
        ];
    }

    public function completeTwoFactorLogin(string $password): void
    {
        $this->client->complete2faLogin($password);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccount(): array
    {
        return $this->client->getSelf();
    }
}
