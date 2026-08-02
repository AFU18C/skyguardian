<?php

namespace Tests\Feature;

use App\Services\SiteHtmlSanitizer;
use Tests\TestCase;

class SiteHtmlSanitizerTest extends TestCase
{
    public function test_cms_html_removes_scripts_events_and_unsafe_links(): void
    {
        $html = '<p onclick="alert(1)">Текст <a href="javascript:alert(2)">опасная ссылка</a></p>'
            .'<script>alert(3)</script><a href="https://example.com" target="_blank">безопасная ссылка</a>';

        $clean = app(SiteHtmlSanitizer::class)->sanitize($html);

        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringContainsString('https://example.com', $clean);
        $this->assertStringContainsString('rel="noreferrer noopener nofollow"', $clean);
    }

    public function test_cms_html_rejects_protocol_relative_external_links(): void
    {
        $clean = app(SiteHtmlSanitizer::class)->sanitize(
            '<a href="//external.example/path">внешняя ссылка</a><a href="/inside">внутренняя</a>',
        );

        $this->assertStringNotContainsString('//external.example', $clean);
        $this->assertStringContainsString('href="/inside"', $clean);
    }
}
