<?php
declare(strict_types=1);

namespace SkyGuardian\Telegram;

final class AccountService
{
    public function __construct(private readonly AccountRepository $accounts) {}

    public function configure(string $id, int $apiId, string $apiHash, string $sessionPath): void
    {
        if ($apiId <= 0 || trim($apiHash) === '' || trim($sessionPath) === '') {
            throw new \InvalidArgumentException('Invalid Telegram account configuration.');
        }

        $this->accounts->save([
            'id' => $id,
            'api_id' => $apiId,
            'api_hash' => trim($apiHash),
            'session_path' => trim($sessionPath),
            'enabled' => false,
            'connected_user' => null,
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function setState(array $account, bool $enabled, ?array $connectedUser = null): void
    {
        $account['enabled'] = $enabled;
        $account['connected_user'] = $connectedUser;
        $account['updated_at'] = gmdate(DATE_ATOM);
        $this->accounts->save($account);
    }
}