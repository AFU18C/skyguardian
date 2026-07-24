<?php

namespace App\Http\Controllers;

use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function news(): View
    {
        return $this->section('news');
    }

    public function alerts(): View
    {
        return $this->section('alerts');
    }

    private function section(string $section): View
    {
        $types = $section === 'news' ? ['news', 'telegram'] : ['alerts'];

        return view('sources.index', [
            'section' => $section,
            'sources' => Source::query()->whereIn('type', $types)->latest()->get(),
        ]);
    }

    public function store(Request $request, string $section): RedirectResponse
    {
        abort_unless(in_array($section, ['news', 'alerts'], true), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'identifier' => ['required', 'string', 'max:255', 'unique:sources,identifier'],
        ]);

        $data['type'] = $section;
        $data['identifier'] = trim($data['identifier']);
        $data['is_active'] = true;

        Source::create($data);

        return back()->with('status', 'Канал или группа добавлены.');
    }

    public function update(Request $request, Source $source): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'identifier' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sources', 'identifier')->ignore($source->id),
            ],
        ]);

        $data['identifier'] = trim($data['identifier']);
        $source->update($data);

        return back()->with('status', 'Канал или группа обновлены.');
    }

    public function toggle(Source $source): RedirectResponse
    {
        $source->update(['is_active' => ! $source->is_active]);

        return back()->with('status', 'Статус изменён.');
    }

    public function destroy(Source $source): RedirectResponse
    {
        $source->delete();

        return back()->with('status', 'Канал или группа удалены.');
    }
}
