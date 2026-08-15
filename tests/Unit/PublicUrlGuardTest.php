<?php

namespace Tests\Unit;

use App\Services\PublicUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PublicUrlGuardTest extends TestCase
{
    #[Test]
    public function it_accepts_a_public_web_address_and_returns_the_pinned_resolution(): void
    {
        $guard = new PublicUrlGuard(fn (string $host): array => ['93.184.216.34']);

        $this->assertSame([
            'url' => 'https://example.com/path',
            'host' => 'example.com',
            'port' => 443,
            'ips' => ['93.184.216.34'],
        ], $guard->inspect('https://example.com/path'));
    }

    #[Test]
    #[DataProvider('blockedUrls')]
    public function it_blocks_internal_special_and_non_web_targets(string $url): void
    {
        $guard = new PublicUrlGuard(fn (string $host): array => ['100.64.0.1']);

        $this->expectException(RuntimeException::class);
        $guard->inspect($url);
    }

    #[Test]
    public function it_rejects_ipv4_translation_and_tunnelling_ranges(): void
    {
        $guard = new PublicUrlGuard;

        $this->assertFalse($guard->isPublicIp('64:ff9b::7f00:1'));
        $this->assertFalse($guard->isPublicIp('2001::1'));
        $this->assertFalse($guard->isPublicIp('2002:7f00:1::'));
    }

    #[Test]
    public function it_accepts_a_public_ipv6_literal_without_dns_resolution(): void
    {
        $guard = new PublicUrlGuard;

        $result = $guard->inspect('https://[2606:4700:4700::1111]/dns-query');

        $this->assertSame('2606:4700:4700::1111', $result['host']);
        $this->assertSame(['2606:4700:4700::1111'], $result['ips']);
    }

    public static function blockedUrls(): array
    {
        return [
            ['http://127.0.0.1/private'],
            ['http://169.254.169.254/latest/meta-data'],
            ['https://example.com:8443/admin'],
            ['https://example.com:0/admin'],
            ['https://user:pass@example.com/'],
            ['file:///etc/passwd'],
            ['https://internal.example/'],
        ];
    }
}
