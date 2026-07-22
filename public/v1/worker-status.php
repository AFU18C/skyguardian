<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SkyGuardian\Config\Paths;
use SkyGuardian\Http\JsonResponse;
use SkyGuardian\Storage\JsonStore;

$paths = new Paths(dirname(__DIR__, 2));
$store = new JsonStore($paths->storage());
$scope = (string) ($_GET['scope'] ?? '');
if ($scope !== '' && !in_array($scope, ['news', 'alerts'], true)) {
    JsonResponse::send(['ok' => false, 'error' => 'Invalid scope.'], 422);
    return;
}

$channels = $store->read('data_channels');
$states = $store->read('channel_states');
$build = static function (string $targetScope) use ($channels, $states): array {
    $items = [];
    foreach ($channels as $channel) {
        if (!is_array($channel) || ($channel['scope'] ?? null) !== $targetScope) continue;
        $id = (string) ($channel['id'] ?? '');
        if ($id === '') continue;
        $items[] = array_replace([
            'id' => $id,
            'enabled' => (bool) ($channel['enabled'] ?? false),
            'status' => 'idle',
            'interval' => (int) ($channel['frequency'] ?? 1) . ' ' . (string) ($channel['frequency_unit'] ?? 'minutes'),
            'next_check' => null,
            'last_check' => null,
            'last_publish' => null,
            'worker_seen' => null,
            'last_message_id' => null,
            'published_count' => 0,
            'last_error' => null,
            'initialized' => false,
        ], is_array($states[$id] ?? null) ? $states[$id] : []);
    }
    return $items;
};

if ($scope !== '') {
    JsonResponse::send(['ok' => true, 'scope' => $scope, 'data' => $build($scope)]);
    return;
}
JsonResponse::send(['ok' => true, 'data' => array_merge($build('news'), $build('alerts'))]);
