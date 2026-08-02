<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\SiteMenuItem;
use App\Models\SitePage;
use App\Models\SiteSetting;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteSettingsCmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_settings_contains_protected_system_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.site-settings'))
            ->assertOk()
            ->assertSee('Страницы сайта')
            ->assertSee('Главная')
            ->assertSee('Контакты')
            ->assertSee('Политика конфиденциальности')
            ->assertSee('Пользовательское соглашение')
            ->assertSee('Страница не найдена');

        $this->assertSame(5, SitePage::query()->where('is_system', true)->count());
    }

    public function test_administrator_can_create_publish_and_render_page_with_blocks(): void
    {
        $user = User::factory()->create();
        $blocks = [
            ['id' => 'heading-1', 'type' => 'heading', 'hidden' => false, 'data' => ['level' => '2', 'text' => 'О проекте']],
            ['id' => 'text-1', 'type' => 'text', 'hidden' => false, 'data' => ['content' => 'Публичное описание SkyGuardian', 'align' => 'left']],
            ['id' => 'button-1', 'type' => 'button', 'hidden' => false, 'data' => ['label' => 'Telegram', 'url' => 'https://t.me/example', 'style' => 'primary', 'new_tab' => true]],
        ];

        $response = $this->actingAs($user)->post(route('admin.site-settings.pages.store'), [
            'title' => 'О проекте',
            'slug' => 'about-project',
            'heading' => 'О SkyGuardian',
            'excerpt' => 'Краткое описание проекта',
            'action' => 'publish',
            'show_in_menu' => '1',
            'menu_label' => 'О проекте',
            'menu_order' => 20,
            'seo_title' => 'О проекте SkyGuardian',
            'seo_description' => 'Описание публичной страницы',
            'blocks_json' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
        ]);

        $page = SitePage::query()->where('slug', 'about-project')->firstOrFail();
        $response
            ->assertRedirect(route('admin.site-settings.pages.edit', $page))
            ->assertSessionHas('toast.type', 'success');

        $this->assertSame(SitePage::STATUS_PUBLISHED, $page->status);
        $this->assertDatabaseHas('site_menu_items', [
            'site_page_id' => $page->id,
            'label' => 'О проекте',
            'is_active' => true,
        ]);

        $this->get('/about-project')
            ->assertOk()
            ->assertSee('О SkyGuardian')
            ->assertSee('Публичное описание SkyGuardian')
            ->assertSee('Telegram')
            ->assertSee('О проекте SkyGuardian', false);
    }

    public function test_draft_is_available_in_preview_but_not_publicly(): void
    {
        $user = User::factory()->create();
        $page = SitePage::query()->create([
            'title' => 'Скрытый черновик',
            'slug' => 'hidden-draft',
            'heading' => 'Черновик',
            'status' => SitePage::STATUS_DRAFT,
            'blocks' => [
                ['id' => 'private', 'type' => 'text', 'hidden' => false, 'data' => ['content' => 'Только предпросмотр']],
            ],
        ]);

        $this->get('/hidden-draft')->assertNotFound();

        $this->actingAs($user)
            ->get(route('admin.site-settings.pages.preview', $page))
            ->assertOk()
            ->assertSee('Предпросмотр страницы')
            ->assertSee('Только предпросмотр');
    }

    public function test_live_alert_map_is_added_to_home_and_can_switch_between_lite_and_full_versions(): void
    {
        $user = User::factory()->create();
        $home = SitePage::query()->where('system_key', 'home')->firstOrFail();

        $this->assertTrue(collect($home->blocks)->contains(
            fn (array $block): bool => ($block['type'] ?? null) === 'alert_map',
        ));

        $this->actingAs($user)
            ->get(route('admin.site-settings.pages.edit', $home))
            ->assertOk()
            ->assertSee('Карта тревог');

        $blocks = [[
            'id' => 'custom-alert-map',
            'type' => 'alert_map',
            'hidden' => false,
            'data' => [
                'title' => 'Актуальные тревоги',
                'show_title' => false,
                'size' => 'compact',
                'mode' => 'full',
                'layout' => 'full',
                'show_link' => true,
                'url' => 'https://attacker.example/map',
            ],
        ]];

        $this->actingAs($user)->put(route('admin.site-settings.pages.update', $home), [
            'title' => $home->title,
            'slug' => $home->slug,
            'heading' => $home->heading,
            'excerpt' => $home->excerpt,
            'action' => 'publish',
            'blocks_json' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
        ])->assertRedirect()->assertSessionHas('toast.type', 'success');

        $savedBlock = $home->fresh()->blocks[0];
        $this->assertSame([
            'title' => 'Актуальные тревоги',
            'show_title' => false,
            'size' => 'compact',
            'mode' => 'full',
            'layout' => 'full',
        ], $savedBlock['data']);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('<h2 class="site-alert-map-title">Актуальные тревоги</h2>', false)
            ->assertSee('src="https://alerts.in.ua/"', false)
            ->assertDontSee('src="https://alerts.in.ua/lite"', false)
            ->assertSee('class="site-block site-alert-map is-full-block"', false)
            ->assertSee('site-alert-map-frame is-compact', false)
            ->assertDontSee('https://attacker.example/map', false)
            ->assertDontSee('Данные обновляются сервисом alerts.in.ua')
            ->assertDontSee('Открыть полную карту');

        $blocks[0]['data']['mode'] = 'lite';
        $blocks[0]['data']['show_title'] = true;
        $blocks[0]['data']['layout'] = 'contained';

        $this->actingAs($user)->put(route('admin.site-settings.pages.update', $home), [
            'title' => $home->title,
            'slug' => $home->slug,
            'heading' => $home->heading,
            'excerpt' => $home->excerpt,
            'action' => 'publish',
            'blocks_json' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
        ])->assertRedirect()->assertSessionHas('toast.type', 'success');

        $this->get('/')
            ->assertOk()
            ->assertSee('<h2 class="site-alert-map-title">Актуальные тревоги</h2>', false)
            ->assertSee('src="https://alerts.in.ua/lite"', false)
            ->assertDontSee('src="https://alerts.in.ua/"', false)
            ->assertDontSee('is-full-block', false);
    }

    public function test_page_hero_can_be_hidden_and_shown_from_the_editor(): void
    {
        $user = User::factory()->create();
        $home = SitePage::query()->where('system_key', 'home')->firstOrFail();

        $this->actingAs($user)
            ->get(route('admin.site-settings.pages.edit', $home))
            ->assertOk()
            ->assertSee('Показывать верхний блок')
            ->assertSee('name="show_hero" type="checkbox" value="1" checked', false);

        $payload = [
            'title' => $home->title,
            'slug' => $home->slug,
            'heading' => $home->heading,
            'excerpt' => $home->excerpt,
            'show_hero' => '0',
            'action' => 'publish',
            'blocks_json' => json_encode($home->blocks, JSON_UNESCAPED_UNICODE),
        ];

        $this->actingAs($user)
            ->put(route('admin.site-settings.pages.update', $home), $payload)
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        $this->assertFalse($home->fresh()->show_hero);
        $this->get('/')
            ->assertOk()
            ->assertDontSee('class="site-page-hero"', false)
            ->assertSee('class="site-main is-at-page-top"', false)
            ->assertSee('class="site-blocks is-at-page-top"', false);

        $payload['show_hero'] = '1';
        $this->actingAs($user)
            ->put(route('admin.site-settings.pages.update', $home), $payload)
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        $this->assertTrue($home->fresh()->show_hero);
        $this->get('/')
            ->assertOk()
            ->assertSee('class="site-page-hero"', false)
            ->assertSee('class="site-main"', false)
            ->assertDontSee('class="site-main is-at-page-top"', false)
            ->assertSee('class="site-blocks"', false)
            ->assertDontSee('is-at-page-top', false);
    }

    public function test_system_page_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $home = SitePage::query()->where('system_key', 'home')->firstOrFail();

        $this->actingAs($user)
            ->delete(route('admin.site-settings.pages.destroy', $home))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error');

        $this->assertDatabaseHas('site_pages', ['id' => $home->id]);
    }

    public function test_menu_supports_external_and_nested_items(): void
    {
        $user = User::factory()->create();
        $home = SiteMenuItem::query()->whereHas('page', fn ($query) => $query->where('system_key', 'home'))->firstOrFail();

        $this->actingAs($user)->post(route('admin.site-settings.menu.store'), [
            'type' => 'external',
            'label' => 'Telegram',
            'url' => 'https://t.me/example',
            'parent_id' => $home->id,
            'sort_order' => 30,
            'open_in_new_tab' => '1',
        ])->assertRedirect()->assertSessionHas('toast.type', 'success');

        $this->assertDatabaseHas('site_menu_items', [
            'label' => 'Telegram',
            'url' => 'https://t.me/example',
            'parent_id' => $home->id,
            'open_in_new_tab' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Telegram')
            ->assertSee('https://t.me/example', false);
    }

    public function test_general_branding_and_media_upload_are_saved(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('admin.site-settings.general.update'), [
            'site_name' => 'SkyGuardian UA',
            'site_tagline' => 'Официальный сайт',
            'language' => 'uk',
            'timezone' => 'Europe/Kyiv',
            'theme' => 'dark',
            'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
            'favicon' => UploadedFile::fake()->image('favicon.png', 64, 64),
        ])->assertRedirect()->assertSessionHas('toast.type', 'success');

        $this->assertSame('SkyGuardian UA', SiteSetting::query()->where('key', 'site_name')->value('value'));
        $this->assertSame('dark', SiteSetting::query()->where('key', 'theme')->value('value'));

        $mediaResponse = $this->actingAs($user)->post(route('admin.site-settings.media.store'), [
            'media' => [UploadedFile::fake()->image('content.jpg', 1200, 800)],
        ], ['Accept' => 'application/json']);

        $mediaResponse
            ->assertOk()
            ->assertJsonCount(1, 'files')
            ->assertJsonStructure(['files' => [['path', 'url', 'name']]]);
    }

    public function test_site_cms_does_not_modify_news_alerts_or_group_channels(): void
    {
        $user = User::factory()->create();
        $sourceCount = Source::query()->count();
        $botCount = GroupChannelBot::query()->count();

        $this->actingAs($user)->post(route('admin.site-settings.pages.store'), [
            'title' => 'Изолированная страница',
            'slug' => 'isolated-page',
            'action' => 'draft',
            'blocks_json' => '[]',
        ])->assertRedirect();

        $this->assertSame($sourceCount, Source::query()->count());
        $this->assertSame($botCount, GroupChannelBot::query()->count());
    }
}
