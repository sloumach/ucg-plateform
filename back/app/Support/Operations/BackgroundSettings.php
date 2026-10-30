<?php

namespace App\Support\Operations;

use App\Support\Queue\QueueName;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final readonly class BackgroundSettings
{
    /** @param list<string> $queues */
    private function __construct(
        public string $store,
        public string $namespace,
        public string $connection,
        public array $queues,
        public int $heartbeatWarningSeconds,
        public int $workWarningSeconds,
        public int $sampleLimit,
    ) {}

    public static function from(Repository $config): self
    {
        $store = self::name($config->get('operations.background.cache_store'));
        $namespace = self::name($config->get('operations.resource_namespace'));
        $connection = self::name($config->get('queue.default'));
        if (! in_array($config->get('cache.stores.'.$store.'.driver'), ['database', 'redis'], true)
            || ! in_array($config->get('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)) {
            throw new InvalidArgumentException('Shared background resources required.');
        }
        $queues = $config->get('operations.background.expected_queues');
        if (! is_array($queues) || ! array_is_list($queues) || count($queues) < 1 || count($queues) > count(QueueName::cases())) {
            throw new InvalidArgumentException('Invalid expected queues.');
        }
        $validated = [];
        foreach ($queues as $queue) {
            if (! is_string($queue) || QueueName::tryFrom($queue) === null || in_array($queue, $validated, true)) {
                throw new InvalidArgumentException('Invalid expected queue.');
            }
            $validated[] = $queue;
        }

        return new self($store, $namespace, $connection, $validated,
            self::limit($config->get('operations.background.heartbeat_warning_seconds'), 60, 86400),
            self::limit($config->get('operations.background.work_warning_seconds'), 60, 86400),
            self::limit($config->get('operations.background.sample_limit'), 1, 10000));
    }

    public function key(string $component): string
    {
        if ($component !== 'scheduler' && ! in_array($component, $this->queues, true)) {
            throw new InvalidArgumentException('Invalid heartbeat component.');
        }

        return 'ops:'.$this->namespace.':background:'.$this->connection.':'.$component;
    }

    private static function name(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_-]{0,62}\z/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid background resource.');
        }

        return $value;
    }

    private static function limit(mixed $value, int $minimum, int $maximum): int
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('Invalid background limit.');
        }

        return $value;
    }
}
