<?php

namespace App\Http\Controllers;

use App\Models\SitePage;
use App\Services\SiteContentService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class SitePageController extends Controller
{
    public function home(SiteContentService $siteContent): View
    {
        if (! $this->contentTablesAvailable()) {
            return view('public.home');
        }

        $page = SitePage::query()
            ->visible()
            ->where('system_key', 'home')
            ->first();

        return $page ? $this->render($page, $siteContent) : view('public.home');
    }

    public function show(string $slug, SiteContentService $siteContent): View|Response
    {
        if (! $this->contentTablesAvailable()) {
            abort(404);
        }

        $page = SitePage::query()
            ->visible()
            ->where('slug', $slug)
            ->first();

        if ($page) {
            return $this->render($page, $siteContent);
        }

        $notFound = SitePage::query()
            ->where('system_key', '404')
            ->first();

        if (! $notFound) {
            abort(404);
        }

        return response()
            ->view('public.page', [
                'page' => $notFound,
                'siteSettings' => $siteContent->settings(),
                'siteMenu' => $siteContent->menu(),
                'isPreview' => false,
            ], 404);
    }

    private function render(SitePage $page, SiteContentService $siteContent): View
    {
        return view('public.page', [
            'page' => $page,
            'siteSettings' => $siteContent->settings(),
            'siteMenu' => $siteContent->menu(),
            'isPreview' => false,
        ]);
    }

    private function contentTablesAvailable(): bool
    {
        try {
            return Schema::hasTable('site_pages');
        } catch (Throwable) {
            return false;
        }
    }
}
