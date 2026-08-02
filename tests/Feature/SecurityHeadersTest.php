<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_responses_have_security_headers(): void
    {
        $response = $this->withServerVariables(['HTTPS' => 'on'])->get('/');

        $response->assertOk();
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('https://www.youtube-nocookie.com', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('https://alerts.in.ua', (string) $response->headers->get('Content-Security-Policy'));
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString("'unsafe-inline'", $policy);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/_=-]+'/", $policy);
        $this->assertStringContainsString("script-src-attr 'none'", $policy);
        $this->assertStringContainsString("style-src-attr 'none'", $policy);
    }
}
