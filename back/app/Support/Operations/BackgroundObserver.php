<?php

namespace App\Support\Operations;

use App\Contracts\Operations\BackgroundHeartbeats;
use App\Support\Queue\QueueName;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Queue\Events\Looping;
use Psr\Log\LoggerInterface;
use Throwable;

final class BackgroundObserver
{
    private const WRITE_INTERVAL_SECONDS = 30;

    /** @var array<string, int> */
    private array $lastAttempts = [];

    public function __construct(
        private readonly Repository $config,
        private readonly BackgroundHeartbeats $heartbeats,
        private readonly LoggerInterface $logger,
    ) {}

    public function looping(Looping $event): void
    {
        if ($this->config->get('operations.background.enabled') !== true
            || $event->connectionName !== $this->config->get('queue.default')) {
            return;
        }
        $queues = explode(',', $event->queue);
        if (count($queues) > count(QueueName::cases())) {
            return;
        }
        foreach (array_unique($queues) as $queue) {
            if (QueueName::tryFrom($queue) !== null) {
                $this->record($queue);
            }
        }
    }

    public function schedulerTick(): void
    {
        $this->record('scheduler');
    }

    private function record(string $component): void
    {
        if ($this->config->get('operations.background.enabled') !== true) {
            return;
        }
        $now = now()->getTimestamp();
        $previous = $this->lastAttempts[$component] ?? null;
        if ($previous !== null && $now >= $previous && $now - $previous < self::WRITE_INTERVAL_SECONDS) {
            return;
        }
        $this->lastAttempts[$component] = $now;
        try {
            $settings = BackgroundSettings::from($this->config);
            if ($component !== 'scheduler' && ! in_array($component, $settings->queues, true)) {
                return;
            }
            $this->heartbeats->record($settings, $component);
        } catch (Throwable) {
            // Monitoring must not turn a successful business job into a retry.
            try {
                $this->logger->warning('ops.background.marker_unavailable', ['component' => $component, 'code' => 'BACKGROUND_HEARTBEAT_WRITE_FAILED']);
            } catch (Throwable) {
                // Preserve the original worker/scheduler behavior if logging also fails.
            }
        }
    }
}
