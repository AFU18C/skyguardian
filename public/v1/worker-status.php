<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SkyGuardian\Config\Paths;
use SkyGuardian\Http\JsonResponse;
use SkyGuardian\Storage\JsonStore;
use SkyGuardian\Worker\WorkerStatusRepository;

$scope = (string) ($_GET['scope'] ?? '');
if (!in_array($scope, ['news', 'alerts'], true)) {
    JsonResponse::send(['ok' => false, 'error' => 'Invalid scope.'], 422);
    return;
}

$paths = new Paths(dirname(__DIR__, 2));
$status = (new WorkerStatusRepository(new JsonStore($paths->storage())))->get($scope);
JsonResponse::send(['ok' => true, 'scope' => $scope, 'worker' => $status]);