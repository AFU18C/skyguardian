<?php
declare(strict_types=1);

namespace SkyGuardian\Worker;

use SkyGuardian\DataChannel\ChannelRepository;

final class DataChannelWorker
{
    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly WorkerStatusRepository $statuses,
    ) {}

    public function run(string $scope, callable $processor): array
    {
        $status = $this->statuses->get($scope);
        $status['status'] = 'running';
        $status['worker_seen'] = gmdate(DATE_ATOM);
        $status['initialized'] = true;
        $status['last_error'] = null;
        $this->statuses->save($scope, $status);

        try {
            foreach ($this->channels->all($scope) as $channel) {
                if (!($channel['enabled'] ?? false)) {
                    continue;
                }
                $result = $processor($channel);
                if (($result['published'] ?? false) === true) {
                    $status['last_publish'] = gmdate(DATE_ATOM);
                    $status['last_message_id'] = $result['message_id'] ?? null;
                    $status['published_count'] = (int) ($status['published_count'] ?? 0) + 1;
                }
            }
            $status['status'] = 'idle';
            $status['last_check'] = gmdate(DATE_ATOM);
        } catch (\Throwable $e) {
            $status['status'] = 'error';
            $status['last_error'] = $e->getMessage();
        }

        $this->statuses->save($scope, $status);
        return $status;
    }
}