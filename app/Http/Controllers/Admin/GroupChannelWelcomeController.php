<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupChannelBot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GroupChannelWelcomeController extends Controller
{
    public function update(Request $request, GroupChannelBot $groupChannelBot): RedirectResponse
    {
        abort_unless($groupChannelBot->moduleEnabled('welcome'), 403);
        $data = $request->validate([
            'photo' => ['required', 'image', 'max:10240'],
        ]);
        $settings = $groupChannelBot->module_settings ?? [];
        $oldPath = data_get($settings, 'welcome.photo');

        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        data_set(
            $settings,
            'welcome.photo',
            $data['photo']->store('group-channel-welcome/'.$groupChannelBot->id),
        );
        $groupChannelBot->update(['module_settings' => $settings]);

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Фото сохранено',
            'message' => 'Приветствие будет отправляться с выбранным фото.',
        ]);
    }

    public function destroy(GroupChannelBot $groupChannelBot): RedirectResponse
    {
        $settings = $groupChannelBot->module_settings ?? [];
        $oldPath = data_get($settings, 'welcome.photo');

        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        data_set($settings, 'welcome.photo', null);
        $groupChannelBot->update(['module_settings' => $settings]);

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Фото удалено',
            'message' => 'Приветствие будет отправляться без фото.',
        ]);
    }
}
