<?php

namespace App\Http\Controllers;

use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function index(): View
    {
        return view('sources.index', [
            'sources' => Source::query()->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:telegram'],
            'identifier' => ['required', 'string', 'max:255', 'unique:sources,identifier'],
        ]);

        $data['identifier'] = trim($data['identifier']);
        $data['is_active'] = true;

        Source::create($data);

        return back()->with('status', 'Источник добавлен.');
    }

    public function update(Request $request, Source $source): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'identifier' => ['required', 'string', 'max:255', 'unique:sources,identifier,'.$source->id],
        ]);

        $source->update($data);

        return back()->with('status', 'Источник обновлён.');
    }

    public function toggle(Source $source): RedirectResponse
    {
        $source->update(['is_active' => ! $source->is_active]);

        return back()->with('status', 'Статус источника изменён.');
    }

    public function destroy(Source $source): RedirectResponse
    {
        $source->delete();

        return back()->with('status', 'Источник удалён.');
    }
}
