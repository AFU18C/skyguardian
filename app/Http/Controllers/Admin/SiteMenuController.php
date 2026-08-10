<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteMenuItem;
use App\Models\SitePage;
use App\Services\SiteContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteMenuController extends Controller
{
    public function store(Request $request, SiteContentService $siteContent): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['page', 'external'])],
            'site_page_id' => ['nullable', 'integer', 'exists:site_pages,id'],
            'label' => ['required', 'string', 'max:120'],
            'url' => ['nullable', 'string', 'max:2048'],
            'parent_id' => ['nullable', 'integer', 'exists:site_menu_items,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'open_in_new_tab' => ['nullable', 'boolean'],
        ]);

        if ($data['type'] === 'page' && empty($data['site_page_id'])) {
            return back()->withErrors(['site_page_id' => 'Выберите опубликованную страницу.'])->withInput();
        }

        if ($data['type'] === 'external' && ! $this->validUrl($data['url'] ?? null)) {
            return back()->withErrors(['url' => 'Введите корректную ссылку http://, https:// или путь /page.'])->withInput();
        }

        $page = isset($data['site_page_id']) ? SitePage::query()->find($data['site_page_id']) : null;
        SiteMenuItem::query()->create([
            'site_page_id' => $data['type'] === 'page' ? $page?->id : null,
            'parent_id' => $data['parent_id'] ?? null,
            'label' => trim($data['label']),
            'url' => $data['type'] === 'external' ? trim((string) $data['url']) : null,
            'sort_order' => (int) ($data['sort_order'] ?? 100),
            'is_active' => true,
            'open_in_new_tab' => $request->boolean('open_in_new_tab'),
        ]);

        if ($page) {
            $page->update([
                'show_in_menu' => true,
                'menu_label' => trim($data['label']),
                'menu_order' => (int) ($data['sort_order'] ?? 100),
                'open_in_new_tab' => $request->boolean('open_in_new_tab'),
            ]);
        }

        $siteContent->clear();

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Пункт меню добавлен',
            'message' => 'Публичное меню сайта обновлено.',
        ]);
    }

    public function update(
        Request $request,
        SiteMenuItem $siteMenuItem,
        SiteContentService $siteContent,
    ): RedirectResponse {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'url' => ['nullable', 'string', 'max:2048'],
            'parent_id' => [
                'nullable',
                'integer',
                'exists:site_menu_items,id',
                Rule::notIn([$siteMenuItem->id]),
            ],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
            'open_in_new_tab' => ['nullable', 'boolean'],
        ]);

        if (! $siteMenuItem->site_page_id && ! $this->validUrl($data['url'] ?? null)) {
            return back()->withErrors(['url' => 'Введите корректную ссылку http://, https:// или путь /page.']);
        }

        $siteMenuItem->update([
            'label' => trim($data['label']),
            'url' => $siteMenuItem->site_page_id ? null : trim((string) ($data['url'] ?? '')),
            'parent_id' => $data['parent_id'] ?? null,
            'sort_order' => (int) $data['sort_order'],
            'is_active' => $request->boolean('is_active'),
            'open_in_new_tab' => $request->boolean('open_in_new_tab'),
        ]);

        if ($siteMenuItem->page) {
            $siteMenuItem->page->update([
                'show_in_menu' => $siteMenuItem->is_active,
                'menu_label' => $siteMenuItem->label,
                'menu_order' => $siteMenuItem->sort_order,
                'open_in_new_tab' => $siteMenuItem->open_in_new_tab,
            ]);
        }

        $siteContent->clear();

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Меню сохранено',
            'message' => 'Положение и параметры пункта обновлены.',
        ]);
    }

    public function destroy(
        SiteMenuItem $siteMenuItem,
        SiteContentService $siteContent,
    ): RedirectResponse {
        $page = $siteMenuItem->page;
        $siteMenuItem->children()->update(['parent_id' => null]);
        $siteMenuItem->delete();

        if ($page) {
            $page->update(['show_in_menu' => false]);
        }

        $siteContent->clear();

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Пункт меню удалён',
            'message' => 'Страница при этом не удалялась.',
        ]);
    }

    private function validUrl(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '' || str_starts_with($url, '//')) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
