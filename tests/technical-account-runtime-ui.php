<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/public/assets/technical-accounts-runtime.js');
$deploy = (string) file_get_contents($root . '/deploy/update-vps.sh');

$checks = [
    "'/technical-accounts.php'" => 'controller must read the canonical server endpoint',
    'updateConnectionPanel' => 'controller must hydrate the connection panel',
    'MutationObserver' => 'controller must detect successful QR completion',
    'technical-accounts-runtime.js' => 'deployment must inject the authoritative controller',
    'asset_version=' => 'deployment must cache-bust frontend assets',
];

foreach ($checks as $needle => $message) {
    $haystack = str_contains($needle, 'technical-accounts-runtime.js') || str_contains($needle, 'asset_version=') ? $deploy : $controller;
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "Runtime UI regression: {$message}.\n");
        exit(1);
    }
}

echo "Technical account runtime UI test passed.\n";
