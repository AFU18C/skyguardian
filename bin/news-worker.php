<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SkyGuardian\Config\Paths;
use SkyGuardian\DataChannel\ChannelRepository;
use SkyGuardian\Storage\JsonStore;
use SkyGuardian\Worker\DataChannelWorker;
use SkyGuardian\Worker\WorkerStatusRepository;

$paths = new Paths(dirname(__DIR__));
$store = new JsonStore($paths->storage());
$worker = new DataChannelWorker(new ChannelRepository($store), new WorkerStatusRepository($store));
$worker->run('news', static fn(array $channel): array => ['published' => false]);