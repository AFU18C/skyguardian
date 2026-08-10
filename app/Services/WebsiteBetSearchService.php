<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WebsiteBetSearchService
{
    private const MAX_REDIRECTS = 3;

    /**
     * @param  array<int, array{name:string,url:string,enabled?:bool}>  $sources
     * @param  array<int, string>  $keywords
     * @return array{messages: array<int, array<string, mixed>>, source_errors: array<int, array{source:string,error:string}>}
     */
    public function search(array $sources, array $keywords, int $limit): array
    {
        $messages = [];
        $errors = [];

        foreach ($sources as $source) {
            if (($source['enabled'] ?? true) !== true || count($messages) >= $limit) {
                continue;
            }

            $name = trim((string) ($source['name'] ?? ''));
            $url = trim((string) ($source['url'] ?? ''));

            try {
                [$response, $finalUrl] = $this->fetch($url);

                if (! $response->successful()) {
                    throw new RuntimeException('HTTP '.$response->status());
                }

                $contentType = mb_strtolower((string) $response->header('Content-Type'));
                if ($contentType !== '' && ! preg_match('~(?:text/|application/(?:xhtml\+xml|xml|rss\+xml|atom\+xml))~i', $contentType)) {
                    throw new RuntimeException('Источник вернул неподдерживаемый Content-Type.');
                }

                $body = $response->body();
                if (strlen($body) > 2_000_000) {
                    $body = substr($body, 0, 2_000_000);
                }

                foreach ($this->candidateTexts($body, $keywords) as $index => $text) {
                    if (count($messages) >= $limit) {
                        break 2;
                    }

                    $messages[] = [
                        'id' => hash('sha256', $finalUrl.'|'.$index.'|'.$text),
                        'date' => now()->toIso8601String(),
                        'text' => $text,
                        'source_type' => 'website',
                        'source_name' => $name !== '' ? $name : (parse_url($finalUrl, PHP_URL_HOST) ?: 'Сайт'),
                        'url' => $finalUrl,
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = ['source' => $name !== '' ? $name : $url, 'error' => $e->getMessage()];
            }
        }

        return ['messages' => $messages, 'source_errors' => $errors];
    }

    /** @return array{0:Response,1:string} */
    private function fetch(string $initialUrl): array
    {
        $url = $initialUrl;

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $target = $this->publicTarget($url);
            $options = ['allow_redirects' => false];

            if (defined('CURLOPT_RESOLVE')) {
                $port = $target['port'];
                $options['curl'] = [CURLOPT_RESOLVE => [
                    $target['host'].':'.$port.':'.$target['ip'],
                ]];
            }

            $response = Http::withOptions($options)
                ->connectTimeout(5)
                ->timeout(15)
                ->retry(1, 250, throw: false)
                ->withHeaders([
                    'User-Agent' => 'SkyGuardian/1.0 (+manual betting search)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8',
                ])
                ->get($url);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return [$response, $url];
            }

            if ($redirects >= self::MAX_REDIRECTS) {
                throw new RuntimeException('Слишком много перенаправлений.');
            }

            $location = trim((string) $response->header('Location'));
            if ($location === '') {
                throw new RuntimeException('Сайт вернул перенаправление без адреса.');
            }

            $url = $this->resolveRedirectUrl($url, $location);
        }

        throw new RuntimeException('Не удалось загрузить сайт.');
    }

    /** @return array{host:string,ip:string,port:int} */
    private function publicTarget(string $url): array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Некорректный адрес сайта.');
        }

        $parts = parse_url($url);
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = mb_strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Разрешены только публичные HTTP/HTTPS адреса.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Адреса с логином или паролем запрещены.');
        }

        if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
            throw new RuntimeException('Нестандартные сетевые порты запрещены.');
        }

        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.lan')
            || str_ends_with($host, '.home')) {
            throw new RuntimeException('Локальные адреса запрещены.');
        }

        $ips = $this->resolveHost($host);
        if ($ips === []) {
            throw new RuntimeException('Не удалось определить публичный IP сайта.');
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new RuntimeException('Источник разрешается в локальный или служебный IP-адрес.');
            }
        }

        return ['host' => $host, 'ip' => $ips[0], 'port' => $port];
    }

    /** @return array<int,string> */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
        }

        if ($ips === []) {
            foreach (@gethostbynamel($host) ?: [] as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $location;
        }

        if (str_starts_with($location, '//')) {
            $scheme = (string) parse_url($baseUrl, PHP_URL_SCHEME);

            return $scheme.':'.$location;
        }

        $scheme = (string) parse_url($baseUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $port = parse_url($baseUrl, PHP_URL_PORT);
        $origin = $scheme.'://'.$host.($port ? ':'.$port : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = (string) parse_url($baseUrl, PHP_URL_PATH);
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        $combined = ($directory !== '' && $directory !== '.') ? $directory.'/'.$location : '/'.$location;
        $segments = [];
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        return $origin.'/'.implode('/', $segments);
    }

    /** @return array<int, string> */
    private function candidateTexts(string $body, array $keywords): array
    {
        $body = preg_replace('~<(script|style|noscript|svg)\b[^>]*>.*?</\1>~isu', ' ', $body) ?? $body;
        $body = preg_replace('~<(?:br|/p|/div|/article|/section|/li|/h[1-6]|/tr)\b[^>]*>~iu', "\n", $body) ?? $body;
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = collect(preg_split('/\R+/u', $text))
            ->map(fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? $line))
            ->filter(fn (string $line): bool => mb_strlen($line) >= 3)
            ->values();

        $needles = collect($keywords)
            ->map(fn (mixed $keyword): string => mb_strtolower(trim((string) $keyword)))
            ->filter()
            ->values();
        $candidates = [];

        foreach ($lines as $index => $line) {
            $lower = mb_strtolower($line);
            $matchesKeyword = $needles->isEmpty() || $needles->contains(fn (string $keyword): bool => str_contains($lower, $keyword));
            if (! $matchesKeyword) {
                continue;
            }

            $start = max(0, $index - 2);
            $candidate = $lines->slice($start, 5)->implode("\n");
            if (mb_strlen($candidate) > 2500) {
                $candidate = mb_substr($candidate, 0, 2500);
            }
            $candidates[hash('sha256', mb_strtolower($candidate))] = $candidate;
        }

        return array_values(array_slice($candidates, 0, 100, true));
    }
}
