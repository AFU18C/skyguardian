<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SkyGuardian\Auth\AdminRepository;
use SkyGuardian\Auth\AuthService;
use SkyGuardian\Auth\PasswordPolicy;
use SkyGuardian\Config\Paths;
use SkyGuardian\Storage\JsonStore;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store, private');

$paths = new Paths(dirname(__DIR__, 2));
$paths->ensureStorage();
$store = new JsonStore($paths->storage());
$authService = new AuthService(new AdminRepository($store), new PasswordPolicy());
