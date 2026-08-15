<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SiteMediaController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'media' => ['required', 'array', 'min:1', 'max:20'],
            'media.*' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:8192'],
        ]);

        $totalBytes = collect($request->file('media', []))
            ->sum(fn ($file): int => max(0, (int) $file->getSize()));
        $maximumBytes = max(1, (int) config('skyguardian.media.site_upload_max_megabytes', 20)) * 1024 * 1024;
        if ($totalBytes > $maximumBytes) {
            throw ValidationException::withMessages([
                'media' => 'Общий размер одной загрузки не может превышать '.(int) ($maximumBytes / 1024 / 1024).' МБ.',
            ]);
        }

        $files = collect($data['media'])
            ->map(function ($file): array {
                $path = $file->store('site/content', 'public');

                return [
                    'path' => $path,
                    'url' => Storage::disk('public')->url($path),
                    'name' => $file->getClientOriginalName(),
                ];
            })
            ->values()
            ->all();

        return response()->json(['files' => $files]);
    }
}
