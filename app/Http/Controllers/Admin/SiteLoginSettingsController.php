<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\SiteContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SiteLoginSettingsController extends Controller
{
    public function edit(SiteContentService $siteContent): View
    {
        return view('admin.site-login-settings', [
            'settings' => $siteContent->settings(),
        ]);
    }

    public function update(Request $request, SiteContentService $siteContent): RedirectResponse
    {
        $data = $request->validate([
            'login_visual_eyebrow' => ['required', 'string', 'max:120'],
            'login_visual_title' => ['required', 'string', 'max:160'],
            'login_visual_description' => ['nullable', 'string', 'max:1000'],
            'login_form_eyebrow' => ['required', 'string', 'max:120'],
            'login_form_title' => ['required', 'string', 'max:160'],
            'login_form_description' => ['nullable', 'string', 'max:500'],
            'login_email_label' => ['required', 'string', 'max:80'],
            'login_password_label' => ['required', 'string', 'max:80'],
            'login_remember_label' => ['required', 'string', 'max:120'],
            'login_button_label' => ['required', 'string', 'max:80'],
            'login_back_label' => ['required', 'string', 'max:120'],
            'login_accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'login_background_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'login_panel_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'login_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'login_background' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'remove_login_logo' => ['nullable', 'boolean'],
            'remove_login_background' => ['nullable', 'boolean'],
        ], [
            'login_accent_color.regex' => 'Основной цвет должен быть указан в формате #687052.',
            'login_background_color.regex' => 'Цвет фона должен быть указан в формате #20231d.',
            'login_panel_color.regex' => 'Цвет панели должен быть указан в формате #f8f5e9.',
        ]);

        $current = $siteContent->settings();
        $logoPath = $current['login_logo_path'] ?? null;
        $backgroundPath = $current['login_background_path'] ?? null;

        if ($request->boolean('remove_login_logo')) {
            $this->deletePublicFile($logoPath);
            $logoPath = null;
        }

        if ($request->boolean('remove_login_background')) {
            $this->deletePublicFile($backgroundPath);
            $backgroundPath = null;
        }

        if ($request->hasFile('login_logo')) {
            $this->deletePublicFile($logoPath);
            $logoPath = $request->file('login_logo')->store('site/login', 'public');
        }

        if ($request->hasFile('login_background')) {
            $this->deletePublicFile($backgroundPath);
            $backgroundPath = $request->file('login_background')->store('site/login', 'public');
        }

        $values = [
            'login_visual_eyebrow' => trim($data['login_visual_eyebrow']),
            'login_visual_title' => trim($data['login_visual_title']),
            'login_visual_description' => trim((string) ($data['login_visual_description'] ?? '')),
            'login_form_eyebrow' => trim($data['login_form_eyebrow']),
            'login_form_title' => trim($data['login_form_title']),
            'login_form_description' => trim((string) ($data['login_form_description'] ?? '')),
            'login_email_label' => trim($data['login_email_label']),
            'login_password_label' => trim($data['login_password_label']),
            'login_remember_label' => trim($data['login_remember_label']),
            'login_button_label' => trim($data['login_button_label']),
            'login_back_label' => trim($data['login_back_label']),
            'login_accent_color' => strtolower($data['login_accent_color']),
            'login_background_color' => strtolower($data['login_background_color']),
            'login_panel_color' => strtolower($data['login_panel_color']),
            'login_logo_path' => $logoPath,
            'login_background_path' => $backgroundPath,
        ];

        foreach ($values as $key => $value) {
            SiteSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $siteContent->clear();

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Страница авторизации сохранена',
            'message' => 'Тексты и оформление входа обновлены. Логика авторизации не изменялась.',
        ]);
    }

    public function preview(SiteContentService $siteContent): View
    {
        return view('auth.login', [
            'siteSettings' => $siteContent->settings(),
            'isPreview' => true,
        ]);
    }

    private function deletePublicFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
