<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteMediaSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_media_upload_enforces_an_aggregate_size_limit(): void
    {
        Storage::fake('public');
        config()->set('skyguardian.media.site_upload_max_megabytes', 1);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.site-settings.media.store'), [
            'media' => [
                UploadedFile::fake()->image('one.jpg')->size(700),
                UploadedFile::fake()->image('two.jpg')->size(700),
            ],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media');

        Storage::disk('public')->assertDirectoryEmpty('site/content');
    }

    public function test_svg_branding_and_favicon_uploads_are_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $payload = [
            'site_name' => 'SkyGuardian',
            'site_tagline' => 'Мониторинг',
            'language' => 'ru',
            'timezone' => 'Europe/Kyiv',
            'theme' => 'classic',
            'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'favicon' => UploadedFile::fake()->createWithContent('favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        ];

        $this->actingAs($user)
            ->from(route('admin.site-settings'))
            ->put(route('admin.site-settings.general.update'), $payload)
            ->assertRedirect(route('admin.site-settings'))
            ->assertSessionHasErrors(['logo', 'favicon']);

        Storage::disk('public')->assertDirectoryEmpty('site/branding');
    }
}
