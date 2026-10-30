<?php

namespace App\Support\Operations;

use App\Contracts\Operations\RuntimeProbe;
use App\Support\Queue\QueueName;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class LaravelRuntimeProbe implements RuntimeProbe
{
    public function __construct(
        private Repository $config,
        private DatabaseManager $database,
        private CacheFactory $cacheFactory,
        private QueueFactory $queueFactory,
    ) {}

    public function database(): RuntimeCheck
    {
        $this->database->connection()->select('select 1');

        return new RuntimeCheck(ProbeState::Passed, 'DATABASE_READ_PASSED');
    }

    public function cache(): RuntimeCheck
    {
        $store = $this->name($this->config->get('cache.default'));
        $driver = $this->config->get('cache.stores.'.$store.'.driver');
        if (in_array($driver, ['array', 'file'], true)) {
            return new RuntimeCheck(ProbeState::Unverified, 'CACHE_READ_INCONCLUSIVE');
        }
        if (! in_array($driver, ['database', 'redis'], true)) {
            return new RuntimeCheck(ProbeState::Unverified, 'CACHE_DRIVER_UNSUPPORTED');
        }

        // A fresh missing key avoids deleting an existing expired cache entry on read.
        $this->cacheFactory->store($store)->get('ops:read-probe:'.Str::uuid()->toString());

        return new RuntimeCheck(ProbeState::Passed, 'CACHE_READ_PASSED', ['persistence_verified' => false, 'write_verified' => false]);
    }

    public function queues(int $sampleLimit, int $warningSeconds): RuntimeCheck
    {
        $connection = $this->name($this->config->get('queue.default'));
        $driver = $this->config->get('queue.connections.'.$connection.'.driver');
        if ($driver === 'redis') {
            $counts = [];
            $queue = $this->queueFactory->connection($connection);
            foreach (QueueName::orderedValues() as $name) {
                $counts[$name] = $queue->size($name);
            }

            return new RuntimeCheck(ProbeState::Unverified, 'QUEUE_TOTALS_ONLY', [
                'scope' => 'registered_queues_only', 'totals' => $counts,
                'oldest_ready_age_verified' => false, 'worker_liveness_verified' => false,
            ]);
        }
        if ($driver !== 'database') {
            return new RuntimeCheck(ProbeState::Unverified, 'QUEUE_DRIVER_UNSUPPORTED');
        }

        $table = $this->name($this->config->get('queue.connections.'.$connection.'.table'));
        $database = $this->nullableName($this->config->get('queue.connections.'.$connection.'.connection'));
        $rows = $this->database->connection($database)->table($table)
            ->whereIn('queue', QueueName::orderedValues())->orderBy('id')->limit($sampleLimit + 1)
            ->get(['id', 'queue', 'reserved_at', 'available_at']);
        $truncated = $rows->count() > $sampleLimit;
        $counts = [];
        foreach (QueueName::orderedValues() as $name) {
            $counts[$name] = ['sampled' => 0, 'ready_observed' => 0, 'reserved_observed' => 0, 'delayed_observed' => 0, 'oldest_ready_age_seconds_observed' => 0];
        }
        $timestamp = now()->getTimestamp();
        $warning = false;
        foreach ($rows->take($sampleLimit) as $row) {
            $name = (string) $row->queue;
            $counts[$name]['sampled']++;
            if ($row->reserved_at !== null) {
                $counts[$name]['reserved_observed']++;
            } elseif ((int) $row->available_at > $timestamp) {
                $counts[$name]['delayed_observed']++;
            } else {
                $age = max(0, $timestamp - (int) $row->available_at);
                $counts[$name]['ready_observed']++;
                $counts[$name]['oldest_ready_age_seconds_observed'] = max($counts[$name]['oldest_ready_age_seconds_observed'], $age);
                $warning = $warning || $age >= $warningSeconds;
            }
        }

        return new RuntimeCheck(
            $warning ? ProbeState::Warning : ($truncated ? ProbeState::Unverified : ProbeState::Passed),
            $warning ? 'QUEUE_READY_WAIT_REVIEW' : ($truncated ? 'QUEUE_SAMPLE_TRUNCATED' : 'QUEUE_METADATA_READ_PASSED'),
            ['scope' => 'registered_queues_only', 'queues' => $counts, 'sample_limit' => $sampleLimit,
                'truncated' => $truncated, 'warning_seconds' => $warningSeconds, 'worker_liveness_verified' => false],
        );
    }

    public function failedJobs(int $sampleLimit): RuntimeCheck
    {
        if (! in_array($this->config->get('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            return new RuntimeCheck(ProbeState::Unverified, 'FAILED_JOBS_DRIVER_UNSUPPORTED');
        }
        $connection = $this->name($this->config->get('queue.default'));
        $table = $this->name($this->config->get('queue.failed.table'));
        $database = $this->nullableName($this->config->get('queue.failed.database'));
        $rows = $this->database->connection($database)->table($table)
            ->where('connection', $connection)->whereIn('queue', QueueName::orderedValues())
            ->orderBy('id')->limit($sampleLimit + 1)->get(['id', 'queue']);
        $truncated = $rows->count() > $sampleLimit;
        $counts = array_fill_keys(QueueName::orderedValues(), 0);
        foreach ($rows->take($sampleLimit) as $row) {
            $counts[(string) $row->queue]++;
        }

        return new RuntimeCheck($rows->isEmpty() ? ProbeState::Passed : ProbeState::Warning,
            $rows->isEmpty() ? 'FAILED_JOBS_METADATA_READ_PASSED' : 'FAILED_JOBS_HISTORY_REVIEW',
            ['scope' => 'configured_connection_registered_queues_only', 'observed' => $counts,
                'truncated' => $truncated, 'sample_limit' => $sampleLimit, 'current_outage_proven' => false]);
    }

    public function privateStorage(): RuntimeCheck
    {
        $disk = $this->name($this->config->get('filesystems.artifacts', $this->config->get('filesystems.default')));
        if ($this->config->get('filesystems.disks.'.$disk.'.driver') !== 'local') {
            return new RuntimeCheck(ProbeState::Unverified, 'STORAGE_DRIVER_UNSUPPORTED');
        }
        $root = $this->config->get('filesystems.disks.'.$disk.'.root');
        if (! is_string($root) || $root === '' || is_link($root)) {
            return new RuntimeCheck(ProbeState::Failed, 'PRIVATE_STORAGE_ROOT_INVALID');
        }
        $resolved = realpath($root);
        if ($resolved === false || ! is_dir($resolved) || ! is_readable($resolved)) {
            return new RuntimeCheck(ProbeState::Failed, 'PRIVATE_STORAGE_ROOT_UNREADABLE');
        }
        if (dirname($resolved) === $resolved) {
            return new RuntimeCheck(ProbeState::Failed, 'PRIVATE_STORAGE_ROOT_INVALID');
        }
        if ($this->config->get('filesystems.disks.'.$disk.'.visibility') === 'public'
            || $this->within($resolved, public_path()) || $this->within($resolved, storage_path('app/public'))
            || $this->within(public_path(), $resolved) || $this->within(storage_path('app/public'), $resolved)) {
            return new RuntimeCheck(ProbeState::Failed, 'PRIVATE_STORAGE_PUBLIC_ROOT');
        }

        return new RuntimeCheck(ProbeState::Passed, 'PRIVATE_STORAGE_METADATA_PASSED', ['files_read' => false, 'write_acl_capacity_verified' => false]);
    }

    private function within(string $path, string $boundary): bool
    {
        $resolved = realpath($boundary);
        if ($resolved === false) {
            return false;
        }
        $path = str_replace('\\', '/', $path);
        $resolved = rtrim(str_replace('\\', '/', $resolved), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $resolved = strtolower($resolved);
        }

        return $path === $resolved || str_starts_with($path, $resolved.'/');
    }

    private function name(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_-]{0,62}\z/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid resource name.');
        }

        return $value;
    }

    private function nullableName(mixed $value): ?string
    {
        return $value === null ? null : $this->name($value);
    }
}
