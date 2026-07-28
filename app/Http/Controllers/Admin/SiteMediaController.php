<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SiteMediaController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'media' => ['required', 'array', 'min:1', 'max:20'],
            'media.*' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:8192'],
        ]);

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
