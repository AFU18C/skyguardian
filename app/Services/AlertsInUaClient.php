<?php

namespace App\Services;

use App\Models\GroupChannelBot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AlertsInUaClient
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeAlerts(string $token): array
    {
        if (trim($token) === '') {
            throw new RuntimeException('Токен alerts.in.ua не указан.');
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout((int) config('services.alerts_in_ua.timeout', 10))
                ->retry(2, 300, throw: false)
                ->get((string) config(
                    'services.alerts_in_ua.active_alerts_url',
                    'https://api.alerts.in.ua/v1/alerts/active.json',
                ));
        } catch (ConnectionException $e) {
            throw new RuntimeException('Не удалось подключиться к alerts.in.ua.', previous: $e);
        }

        $body = $response->json();

        if (! $response->successful()) {
            $message = is_array($body) ? (string) ($body['message'] ?? '') : '';
            $message = trim($message);

            throw new RuntimeException($message !== ''
                ? 'alerts.in.ua: '.$message
                : 'alerts.in.ua вернул HTTP '.$response->status().'.');
        }

        $alerts = is_array($body) ? ($body['alerts'] ?? null) : null;

        if (! is_array($alerts)) {
            throw new RuntimeException('alerts.in.ua вернул ответ в неизвестном формате.');
        }

        return array_values(array_map(
            fn (array $alert): array => $this->normalizeOblastUid($alert),
            array_filter($alerts, 'is_array'),
        ));
    }

    /**
     * Child locations may contain their own UID in location_oblast_uid.
     * Resolve the real top-level region from the oblast name used by the API.
     *
     * @param  array<string, mixed>  $alert
     * @return array<string, mixed>
     */
    private function normalizeOblastUid(array $alert): array
    {
        $oblastName = $this->normalizedRegionName((string) ($alert['location_oblast'] ?? ''));

        if ($oblastName !== '') {
            foreach (GroupChannelBot::ALERT_REGIONS as $uid => $name) {
                if ($this->normalizedRegionName($name) === $oblastName) {
                    $alert['location_oblast_uid'] = (string) $uid;

                    return $alert;
                }
            }
        }

        $locationUid = trim((string) ($alert['location_uid'] ?? ''));

        if (($alert['location_type'] ?? null) === 'oblast'
            && array_key_exists($locationUid, GroupChannelBot::ALERT_REGIONS)) {
            $alert['location_oblast_uid'] = $locationUid;
        }

        return $alert;
    }

    private function normalizedRegionName(string $name): string
    {
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));

        return preg_replace('/^м\.\s*/u', '', $name) ?? $name;
    }
}
