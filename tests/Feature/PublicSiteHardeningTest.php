<?php

namespace Tests\Feature;

use App\Models\SitePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicSiteHardeningTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function public_page_has_canonical_structured_metadata_and_an_h1_without_hero(): void
    {
        $page = SitePage::query()->create([
            'title' => 'Безопасная страница',
            'slug' => 'safe-page',
            'heading' => 'Безопасная страница',
            'seo_title' => 'Безопасная страница SkyGuardian',
            'seo_description' => 'Проверка метаданных публичного сайта.',
            'show_hero' => false,
            'status' => SitePage::STATUS_PUBLISHED,
            'published_at' => now(),
            'blocks' => [[
                'id' => 'button-1',
                'type' => 'button',
                'hidden' => false,
                'data' => [
                    'label' => 'Опасная ссылка',
                    'url' => '//evil.example/path',
                ],
            ]],
        ]);

        $response = $this->get('/'.$page->slug)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/safe-page').'">', false)
            ->assertSee('<meta name="twitter:card"', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('<h1 class="sr-only">Безопасная страница</h1>', false)
            ->assertDontSee('//evil.example/path', false);

        $this->assertStringNotContainsString('login-page.css', $response->getContent());
    }

    #[Test]
    public function protocol_relative_menu_urls_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.site-settings.menu.store'), [
            'type' => 'external',
            'label' => 'Unsafe',
            'url' => '//evil.example/path',
            'sort_order' => 10,
        ])->assertSessionHasErrors('url');

        $this->assertDatabaseMissing('site_menu_items', ['label' => 'Unsafe']);
    }

    #[Test]
    public function robots_file_points_to_sitemap_and_blocks_admin_crawling(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));
        $sitemap = file_get_contents(public_path('sitemap.xml'));

        $this->assertIsString($robots);
        $this->assertStringContainsString('Disallow: /admin/', $robots);
        $this->assertStringContainsString('Sitemap: https://skyguardian.pp.ua/sitemap.xml', $robots);
        $this->assertIsString($sitemap);
        $this->assertStringContainsString('<loc>https://skyguardian.pp.ua/</loc>', $sitemap);
    }
}
