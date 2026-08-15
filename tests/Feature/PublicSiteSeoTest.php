<?php

namespace Tests\Feature;

use App\Models\SitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSiteSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_page_has_canonical_social_metadata_and_shared_cache_headers(): void
    {
        $page = SitePage::query()->where('system_key', 'home')->firstOrFail();
        $page->update([
            'seo_title' => 'SkyGuardian — карта тревог',
            'seo_description' => 'Актуальная публичная информация SkyGuardian.',
        ]);

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/').'">', false)
            ->assertSee('<meta name="robots" content="index,follow,max-image-preview:large">', false)
            ->assertSee('<meta property="og:title"', false)
            ->assertSee('<meta name="twitter:card"', false)
            ->assertHeader('Cache-Control', 'public, max-age=300, s-maxage=600, stale-while-revalidate=60');
        $this->assertNotNull($response->headers->get('ETag'));
        $this->assertNull($response->headers->get('Set-Cookie'));

        $notModified = $this->withHeader('If-None-Match', (string) $response->headers->get('ETag'))
            ->get('/');
        $notModified->assertStatus(304);
        $this->assertNull($notModified->headers->get('Content-Security-Policy'));
    }

    public function test_sitemap_contains_only_published_pages_and_robots_points_to_it(): void
    {
        SitePage::query()->create([
            'title' => 'Опубликовано',
            'slug' => 'published-page',
            'status' => SitePage::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        SitePage::query()->create([
            'title' => 'Черновик',
            'slug' => 'draft-page',
            'status' => SitePage::STATUS_DRAFT,
        ]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee(url('/published-page'), false)
            ->assertDontSee(url('/draft-page'), false)
            ->assertDontSee(url('/404'), false)
            ->assertDontSee('/admin');

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /admin')
            ->assertSee(route('sitemap'));
    }

    public function test_not_found_and_preview_pages_are_not_indexed(): void
    {
        $draft = SitePage::query()->create([
            'title' => 'Черновик',
            'slug' => 'private-preview',
            'status' => SitePage::STATUS_DRAFT,
        ]);

        $missing = $this->get('/missing-page');
        $missing
            ->assertNotFound()
            ->assertSee('<link rel="canonical" href="'.url('/missing-page').'">', false)
            ->assertSee('<meta name="robots" content="noindex,nofollow">', false);
        $this->assertNull($missing->headers->get('Set-Cookie'));
        $missing->assertHeader('Cache-Control', 'no-store, max-age=0');

        $this->get('/404')
            ->assertNotFound()
            ->assertSee('<meta name="robots" content="noindex,nofollow">', false);

        $this->actingAs(\App\Models\User::factory()->create())
            ->get(route('admin.site-settings.pages.preview', $draft))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex,nofollow">', false);
    }
}
