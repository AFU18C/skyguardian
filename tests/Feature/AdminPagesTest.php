<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;
    /**
     * @dataProvider pageProvider
     */
    public function test_admin_pages_are_available(string $uri): void
    {
        $this->get($uri)->assertOk();
    }

    public static function pageProvider(): array
    {
        return [
            'home' => ['/'],
            'news' => ['/news'],
            'news sources' => ['/news/sources'],
            'news source create' => ['/news/sources/create'],
            'news settings' => ['/news/settings'],
            'air alert' => ['/air-alert'],
            'alert sources' => ['/alerts/sources'],
            'alert source create' => ['/alerts/sources/create'],
            'alert settings' => ['/alerts/settings'],
        ];
    }
}
