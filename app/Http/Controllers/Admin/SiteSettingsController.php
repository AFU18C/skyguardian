<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteMenuItem;
use App\Models\SitePage;
use App\Models\SiteSetting;
use App\Services\SiteContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SiteSettingsController extends Controller
{
    public function index(SiteContentService $siteContent): View
    {
        return view('admin.site-settings', [
            'pages' => SitePage::query()->latest('updated_at')->paginate(20),
            'settings' => $siteContent->settings(),
            'menuItems' => SiteMenuItem::query()
                ->with(['page', 'parent'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'parentMenuItems' => SiteMenuItem::query()
                ->whereNull('parent_id')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'publishedPages' => SitePage::query()
                ->where('status', SitePage::STATUS_PUBLISHED)
                ->orderBy('title')
                ->get(),
        ]);
    }

    public function updateGeneral(
        Request $request,
        SiteContentService $siteContent,
    ): RedirectResponse {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:120'],
            'site_tagline' => ['nullable', 'string', 'max:255'],
            'language' => ['required', Rule::in(['ru', 'uk'])],
            'timezone' => ['required', Rule::in(['Europe/Kyiv', 'UTC'])],
            'theme' => ['required', Rule::in(['classic', 'light', 'dark'])],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:4096'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico,svg', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
        ]);

        $current = $siteContent->settings();
        $logoPath = $current['logo_path'] ?? null;
        $faviconPath = $current['favicon_path'] ?? null;

        if ($request->boolean('remove_logo')) {
            $this->deletePublicFile($logoPath);
            $logoPath = null;
        }

        if ($request->boolean('remove_favicon')) {
            $this->deletePublicFile($faviconPath);
            $faviconPath = null;
        }

        if ($request->hasFile('logo')) {
            $this->deletePublicFile($logoPath);
            $logoPath = $request->file('logo')->store('site/branding', 'public');
        }

        if ($request->hasFile('favicon')) {
            $this->deletePublicFile($faviconPath);
            $faviconPath = $request->file('favicon')->store('site/branding', 'public');
        }

        foreach ([
            'site_name' => $data['site_name'],
            'site_tagline' => $data['site_tagline'] ?? '',
            'language' => $data['language'],
            'timezone' => $data['timezone'],
            'theme' => $data['theme'],
            'logo_path' => $logoPath,
            'favicon_path' => $faviconPath,
        ] as $key => $value) {
            SiteSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $siteContent->clear();

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Настройки сохранены',
            'message' => 'Общие параметры публичного сайта обновлены.',
        ]);
    }

    private function deletePublicFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
