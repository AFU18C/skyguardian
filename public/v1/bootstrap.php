<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SkyGuardian\Auth\AdminRepository;
use SkyGuardian\Auth\AuthService;
use SkyGuardian\Auth\PasswordPolicy;
use SkyGuardian\Config\Paths;
use SkyGuardian\Storage\JsonStore;

$paths = new Paths(dirname(__DIR__, 2));
$paths->ensureStorage();
$store = new JsonStore($paths->storage());
$authService = new AuthService(new AdminRepository($store), new PasswordPolicy());
