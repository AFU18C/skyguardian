<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteMenuItem;
use App\Models\SitePage;
use App\Services\SiteContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use JsonException;

class SitePageController extends Controller
{
    private const BLOCK_TYPES = [
        'heading',
        'text',
        'image',
        'gallery',
        'video',
        'button',
        'list',
        'card',
        'divider',
        'columns',
        'contacts',
        'telegram',
        'html',
    ];

    public function create(): View
    {
        return view('admin.site-page-editor', [
            'page' => new SitePage([
                'status' => SitePage::STATUS_DRAFT,
                'menu_order' => 100,
                'blocks' => [],
            ]),
        ]);
    }

    public function store(Request $request, SiteContentService $siteContent): RedirectResponse
    {
        $data = $this->validatePage($request);
        $page = new SitePage();
        $payload = $this->payload($request, $data, $page, $siteContent);
        $page->fill($payload);
        $page->is_system = false;
        $page->system_key = null;
        $page->save();
        $this->syncMenu($page);
        $siteContent->clear();

        return redirect()
            ->route('admin.site-settings.pages.edit', $page)
            ->with('toast', $this->savedToast($page));
    }

    public function edit(SitePage $sitePage): View
    {
        return view('admin.site-page-editor', ['page' => $sitePage]);
    }

    public function update(
        Request $request,
        SitePage $sitePage,
        SiteContentService $siteContent,
    ): RedirectResponse {
        $data = $this->validatePage($request, $sitePage);
        $payload = $this->payload($request, $data, $sitePage, $siteContent);

        if ($sitePage->is_system) {
            $payload['slug'] = $sitePage->slug;
            $payload['system_key'] = $sitePage->system_key;
            $payload['is_system'] = true;
        }

        $sitePage->update($payload);
        $this->syncMenu($sitePage);
        $siteContent->clear();

        return back()->with('toast', $this->savedToast($sitePage));
    }

    public function destroy(SitePage $sitePage, SiteContentService $siteContent): RedirectResponse
    {
        if ($sitePage->is_system) {
            return back()->with('toast', [
                'type' => 'error',
                'title' => 'Страница защищена',
                'message' => 'Системную страницу можно редактировать, но нельзя удалить.',
            ]);
        }

        $this->deletePublicFile($sitePage->featured_image_path);
        $this->deletePublicFile($sitePage->social_image_path);
        $sitePage->delete();
        $siteContent->clear();

        return redirect()->route('admin.site-settings')->with('toast', [
            'type' => 'success',
            'title' => 'Страница удалена',
            'message' => 'Страница и связанный пункт меню удалены.',
        ]);
    }

    public function duplicate(SitePage $sitePage, SiteContentService $siteContent): RedirectResponse
    {
        $copy = $sitePage->replicate([
            'is_system',
            'system_key',
            'status',
            'published_at',
            'show_in_menu',
            'featured_image_path',
            'social_image_path',
        ]);
        $copy->title = $sitePage->title.' — копия';
        $copy->slug = $this->uniqueSlug($sitePage->slug.'-copy');
        $copy->status = SitePage::STATUS_DRAFT;
        $copy->is_system = false;
        $copy->system_key = null;
        $copy->show_in_menu = false;
        $copy->published_at = null;
        $copy->featured_image_path = null;
        $copy->social_image_path = null;
        $copy->save();
        $siteContent->clear();

        return redirect()
            ->route('admin.site-settings.pages.edit', $copy)
            ->with('toast', [
                'type' => 'success',
                'title' => 'Копия создана',
                'message' => 'Новая страница сохранена как черновик.',
            ]);
    }

    public function preview(SitePage $sitePage, SiteContentService $siteContent): View
    {
        return view('public.page', [
            'page' => $sitePage,
            'siteSettings' => $siteContent->settings(),
            'siteMenu' => $siteContent->menu(),
            'isPreview' => true,
        ]);
    }

    private function validatePage(Request $request, ?SitePage $page = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'slug' => [
                'required',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('site_pages', 'slug')->ignore($page?->id),
                Rule::notIn(['admin', 'telegram']),
            ],
            'heading' => ['nullable', 'string', 'max:220'],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'action' => ['required', Rule::in(['draft', 'publish', 'hide'])],
            'published_at' => ['nullable', 'date'],
            'show_in_menu' => ['nullable', 'boolean'],
            'menu_label' => ['nullable', 'string', 'max:120'],
            'menu_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'open_in_new_tab' => ['nullable', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'featured_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'social_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'remove_featured_image' => ['nullable', 'boolean'],
            'remove_social_image' => ['nullable', 'boolean'],
            'blocks_json' => ['nullable', 'string', 'max:500000'],
        ]);
    }

    private function payload(
        Request $request,
        array $data,
        SitePage $page,
        SiteContentService $siteContent,
    ): array {
        $status = match ($data['action']) {
            'publish' => SitePage::STATUS_PUBLISHED,
            'hide' => SitePage::STATUS_HIDDEN,
            default => SitePage::STATUS_DRAFT,
        };

        $publishedAt = null;
        if ($status === SitePage::STATUS_PUBLISHED) {
            $timezone = (string) ($siteContent->settings()['timezone'] ?? 'Europe/Kyiv');
            $publishedAt = filled($data['published_at'] ?? null)
                ? Carbon::parse($data['published_at'], $timezone)->utc()
                : ($page->published_at?->isFuture() ? $page->published_at : now());
        }

        $featuredPath = $page->featured_image_path;
        $socialPath = $page->social_image_path;

        if ($request->boolean('remove_featured_image')) {
            $this->deletePublicFile($featuredPath);
            $featuredPath = null;
        }
        if ($request->boolean('remove_social_image')) {
            $this->deletePublicFile($socialPath);
            $socialPath = null;
        }
        if ($request->hasFile('featured_image')) {
            $this->deletePublicFile($featuredPath);
            $featuredPath = $request->file('featured_image')->store('site/pages', 'public');
        }
        if ($request->hasFile('social_image')) {
            $this->deletePublicFile($socialPath);
            $socialPath = $request->file('social_image')->store('site/social', 'public');
        }

        return [
            'title' => trim($data['title']),
            'slug' => Str::slug($data['slug']),
            'heading' => trim((string) ($data['heading'] ?? '')) ?: null,
            'excerpt' => trim((string) ($data['excerpt'] ?? '')) ?: null,
            'status' => $status,
            'show_in_menu' => $request->boolean('show_in_menu'),
            'menu_label' => trim((string) ($data['menu_label'] ?? '')) ?: null,
            'menu_order' => (int) ($data['menu_order'] ?? 100),
            'open_in_new_tab' => $request->boolean('open_in_new_tab'),
            'published_at' => $publishedAt,
            'featured_image_path' => $featuredPath,
            'social_image_path' => $socialPath,
            'seo_title' => trim((string) ($data['seo_title'] ?? '')) ?: null,
            'seo_description' => trim((string) ($data['seo_description'] ?? '')) ?: null,
            'blocks' => $this->parseBlocks($data['blocks_json'] ?? '[]'),
        ];
    }

    private function parseBlocks(string $json): array
    {
        try {
            $blocks = json_decode($json ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($blocks)) {
            return [];
        }

        return collect($blocks)
            ->filter(fn ($block): bool => is_array($block) && in_array($block['type'] ?? null, self::BLOCK_TYPES, true))
            ->take(150)
            ->map(function (array $block): array {
                $data = is_array($block['data'] ?? null) ? $block['data'] : [];
                foreach ($data as $key => $value) {
                    if (is_string($value)) {
                        $data[$key] = Str::limit(trim($value), 50000, '');
                    }
                }

                foreach (['html', 'left_html', 'right_html'] as $htmlKey) {
                    if (isset($data[$htmlKey])) {
                        $data[$htmlKey] = $this->sanitizeHtml((string) $data[$htmlKey]);
                    }
                }

                foreach (['url', 'src', 'link_url', 'telegram_url'] as $urlKey) {
                    if (isset($data[$urlKey])) {
                        $data[$urlKey] = $this->safeUrl((string) $data[$urlKey]);
                    }
                }

                return [
                    'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($block['id'] ?? Str::uuid())) ?: (string) Str::uuid(),
                    'type' => $block['type'],
                    'hidden' => (bool) ($block['hidden'] ?? false),
                    'data' => $data,
                ];
            })
            ->values()
            ->all();
    }

    private function sanitizeHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/javascript\s*:/i', '', $html) ?? '';

        return strip_tags($html, '<p><br><strong><b><em><i><u><s><ul><ol><li><blockquote><a><h2><h3><h4><table><thead><tbody><tr><th><td><span>');
    }

    private function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || Str::startsWith($url, ['/'])) {
            return $url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https', 'mailto', 'tel'], true)
            ? $url
            : '';
    }

    private function syncMenu(SitePage $page): void
    {
        if (! $page->show_in_menu) {
            $page->menuItem()->delete();

            return;
        }

        SiteMenuItem::query()->updateOrCreate(
            ['site_page_id' => $page->id],
            [
                'parent_id' => null,
                'label' => $page->menu_label ?: $page->title,
                'url' => null,
                'sort_order' => $page->menu_order,
                'is_active' => true,
                'open_in_new_tab' => $page->open_in_new_tab,
            ],
        );
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'page';
        $candidate = $slug;
        $index = 2;

        while (SitePage::query()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$index++;
        }

        return $candidate;
    }

    private function savedToast(SitePage $page): array
    {
        return [
            'type' => 'success',
            'title' => 'Страница сохранена',
            'message' => match ($page->status) {
                SitePage::STATUS_PUBLISHED => $page->published_at?->isFuture()
                    ? 'Страница будет опубликована в указанное время.'
                    : 'Страница опубликована на сайте.',
                SitePage::STATUS_HIDDEN => 'Страница сохранена и скрыта с публичного сайта.',
                default => 'Изменения сохранены как черновик.',
            },
        ];
    }

    private function deletePublicFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
