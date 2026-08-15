<?php

namespace App\Services;

use Closure;
use RuntimeException;

class PublicUrlGuard
{
    /** @param null|Closure(string): array<int, string> $resolver */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /** @return array{url:string,host:string,port:int,ips:array<int,string>} */
    public function inspect(string $url): array
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Некорректный адрес сайта.');
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Разрешены только HTTP и HTTPS адреса.');
        }

        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new RuntimeException('Адреса со встроенными учётными данными запрещены.');
        }

        $host = mb_strtolower(rtrim(trim((string) parse_url($url, PHP_URL_HOST), '[]'), '.'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new RuntimeException('Локальные адреса запрещены.');
        }

        $explicitPort = parse_url($url, PHP_URL_PORT);
        $port = $explicitPort !== null ? (int) $explicitPort : ($scheme === 'https' ? 443 : 80);
        if (! is_int($port) || ! in_array($port, [80, 443], true)) {
            throw new RuntimeException('Разрешены только стандартные веб-порты 80 и 443.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolve($host);

        if ($ips === []) {
            throw new RuntimeException('Не удалось определить IP-адрес сайта.');
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new RuntimeException('Адрес сайта ведёт во внутреннюю или служебную сеть.');
            }
        }

        return [
            'url' => $url,
            'host' => $host,
            'port' => $port,
            'ips' => array_values(array_unique($ips)),
        ];
    }

    public function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $blocked = str_contains($ip, ':')
            ? ['::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8']
            : ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];

        return ! collect($blocked)->contains(fn (string $range): bool => $this->inCidr($ip, $range));
    }

    /** @return array<int, string> */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return array_values(array_filter(($this->resolver)($host), 'is_string'));
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        return collect($records)
            ->map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter(fn (mixed $ip): bool => is_string($ip) && $ip !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);
        $addressBytes = inet_pton($ip);
        $networkBytes = inet_pton($network);
        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $bits = (int) $prefix;
        for ($index = 0; $index < strlen($addressBytes); $index++) {
            $mask = $bits >= 8 ? 0xff : ($bits <= 0 ? 0 : (0xff << (8 - $bits)) & 0xff);
            if ((ord($addressBytes[$index]) & $mask) !== (ord($networkBytes[$index]) & $mask)) {
                return false;
            }
            $bits -= 8;
        }

        return true;
    }
}
