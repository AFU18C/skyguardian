<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(): View
    {
        $requiredExtensions = [
            'mbstring',
            'xml',
            'json',
            'fileinfo',
            'gmp',
            'openssl',
            'iconv',
            'gd',
        ];

        $extensions = collect($requiredExtensions)
            ->mapWithKeys(fn (string $extension): array => [$extension => extension_loaded($extension)]);

        return view('integrations.index', [
            'madelineInstalled' => class_exists(\danog\MadelineProto\API::class),
            'extensions' => $extensions,
            'requirementsReady' => $extensions->every(fn (bool $loaded): bool => $loaded),
        ]);
    }
}
