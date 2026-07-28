<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('heading')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->boolean('is_system')->default(false);
            $table->string('system_key', 48)->nullable()->unique();
            $table->boolean('show_in_menu')->default(false);
            $table->string('menu_label')->nullable();
            $table->unsignedInteger('menu_order')->default(100);
            $table->boolean('open_in_new_tab')->default(false);
            $table->timestamp('published_at')->nullable()->index();
            $table->string('featured_image_path')->nullable();
            $table->string('social_image_path')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->json('blocks')->nullable();
            $table->timestamps();
        });

        Schema::create('site_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('site_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_page_id')->nullable()->constrained('site_pages')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('site_menu_items')->nullOnDelete();
            $table->string('label');
            $table->string('url')->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->boolean('is_active')->default(true);
            $table->boolean('open_in_new_tab')->default(false);
            $table->timestamps();
        });

        $now = now();
        $pages = [
            [
                'title' => 'Главная',
                'slug' => 'home',
                'heading' => 'SkyGuardian',
                'excerpt' => 'Система мониторинга информации',
                'status' => 'published',
                'is_system' => true,
                'system_key' => 'home',
                'show_in_menu' => true,
                'menu_label' => 'Главная',
                'menu_order' => 10,
                'published_at' => $now,
                'blocks' => json_encode([
                    ['id' => 'home-lead', 'type' => 'heading', 'hidden' => false, 'data' => ['level' => '2', 'text' => 'Сайт находится в разработке']],
                    ['id' => 'home-copy', 'type' => 'text', 'hidden' => false, 'data' => ['content' => 'Содержимое главной страницы можно изменить в разделе «Настройки сайта».', 'align' => 'center']],
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'title' => 'Контакты',
                'slug' => 'contacts',
                'heading' => 'Контакты',
                'status' => 'draft',
                'is_system' => true,
                'system_key' => 'contacts',
                'show_in_menu' => false,
                'menu_label' => 'Контакты',
                'menu_order' => 80,
                'blocks' => json_encode([], JSON_UNESCAPED_UNICODE),
            ],
            [
                'title' => 'Политика конфиденциальности',
                'slug' => 'privacy',
                'heading' => 'Политика конфиденциальности',
                'status' => 'draft',
                'is_system' => true,
                'system_key' => 'privacy',
                'show_in_menu' => false,
                'menu_label' => 'Конфиденциальность',
                'menu_order' => 90,
                'blocks' => json_encode([], JSON_UNESCAPED_UNICODE),
            ],
            [
                'title' => 'Пользовательское соглашение',
                'slug' => 'terms',
                'heading' => 'Пользовательское соглашение',
                'status' => 'draft',
                'is_system' => true,
                'system_key' => 'terms',
                'show_in_menu' => false,
                'menu_label' => 'Соглашение',
                'menu_order' => 100,
                'blocks' => json_encode([], JSON_UNESCAPED_UNICODE),
            ],
            [
                'title' => 'Страница не найдена',
                'slug' => '404',
                'heading' => 'Страница не найдена',
                'status' => 'published',
                'is_system' => true,
                'system_key' => '404',
                'show_in_menu' => false,
                'menu_order' => 999,
                'published_at' => $now,
                'blocks' => json_encode([
                    ['id' => 'not-found-copy', 'type' => 'text', 'hidden' => false, 'data' => ['content' => 'Запрошенная страница не существует или ещё не опубликована.', 'align' => 'center']],
                    ['id' => 'not-found-button', 'type' => 'button', 'hidden' => false, 'data' => ['label' => 'На главную', 'url' => '/', 'style' => 'primary', 'new_tab' => false]],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        foreach ($pages as $page) {
            DB::table('site_pages')->insert(array_merge($page, [
                'open_in_new_tab' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $homeId = DB::table('site_pages')->where('system_key', 'home')->value('id');
        DB::table('site_menu_items')->insert([
            'site_page_id' => $homeId,
            'parent_id' => null,
            'label' => 'Главная',
            'url' => null,
            'sort_order' => 10,
            'is_active' => true,
            'open_in_new_tab' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
            'site_name' => 'SkyGuardian',
            'site_tagline' => 'Система мониторинга информации',
            'language' => 'ru',
            'timezone' => 'Europe/Kyiv',
            'theme' => 'classic',
            'logo_path' => null,
            'favicon_path' => null,
        ] as $key => $value) {
            DB::table('site_settings')->insert([
                'key' => $key,
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_menu_items');
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('site_pages');
    }
};
