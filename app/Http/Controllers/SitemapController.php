<?php

namespace App\Http\Controllers;

use App\Models\SitePage;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        try {
            $pages = Schema::hasTable('site_pages')
                ? SitePage::query()
                    ->visible()
                    ->where(function ($query): void {
                        $query->whereNull('system_key')->orWhere('system_key', '!=', '404');
                    })
                    ->orderBy('id')
                    ->get()
                : collect();
        } catch (Throwable) {
            $pages = collect();
        }

        $urls = $pages->map(fn (SitePage $page): array => [
            'location' => $page->publicUrl(),
            'lastModified' => $page->updated_at?->toAtomString(),
        ]);

        if ($urls->isEmpty()) {
            $urls->push(['location' => url('/'), 'lastModified' => null]);
        }

        $xml = view('public.sitemap', ['urls' => $urls])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300, s-maxage=900, stale-while-revalidate=60',
        ]);
    }
}
