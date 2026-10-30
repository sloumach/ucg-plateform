<?php

namespace Tests\Feature;

use App\Contracts\Operations\RuntimeProbe;
use App\Support\Operations\ProbeState;
use App\Support\Operations\RuntimeCheck;
use App\Support\Operations\RuntimeDiagnostics;
use App\Support\Queue\QueueName;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RuntimeDiagnosticsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_does_not_access_dependencies_without_explicit_probe(): void
    {
        $probe = $this->mock(RuntimeProbe::class);
        foreach (['database', 'cache', 'queues', 'failedJobs', 'privateStorage'] as $method) {
            $probe->shouldNotReceive($method);
        }
        config(['database.connections.sqlite.database' => '/sensitive-sentinel/missing.sqlite']);

        $this->assertSame(2, Artisan::call('ops:inspect-runtime', ['--json' => true]));
        $report = Artisan::output();
        $this->assertStringContainsString('PROBE_NOT_REQUESTED', $report);
        $this->assertStringContainsString('"probe_requested":false', $report);
        $this->assertStringNotContainsString('sensitive-sentinel', $report);
    }

    public function test_command_reads_only_metadata_and_preserves_queue_cache_and_failed_records(): void
    {
        $this->configureDatabaseProbes();
        DB::table('cache')->insert(['key' => 'expired-sensitive-sentinel', 'value' => 'sensitive-sentinel', 'expiration' => 1]);
        $this->insertJob(['available_at' => now()->getTimestamp() + 600]);
        $before = [DB::table('jobs')->get()->toJson(), DB::table('cache')->get()->toJson(), DB::table('failed_jobs')->get()->toJson()];
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $this->assertSame(0, Artisan::call('ops:inspect-runtime', ['--probe' => true, '--json' => true]));
        $report = Artisan::output();
        $observedQueries = $queries;

        $this->assertCount(4, $observedQueries);
        foreach ($observedQueries as $sql) {
            $this->assertStringStartsWith('select ', strtolower($sql));
            if (str_contains($sql, 'jobs')) {
                $this->assertStringNotContainsString('*', $sql);
                $this->assertStringNotContainsString('payload', $sql);
                $this->assertStringNotContainsString('exception', $sql);
                $this->assertStringContainsString('limit 1001', $sql);
            }
        }
        $this->assertSame($before, [DB::table('jobs')->get()->toJson(), DB::table('cache')->get()->toJson(), DB::table('failed_jobs')->get()->toJson()]);
        $this->assertStringContainsString('"status":"probes_passed"', $report);
        $this->assertStringContainsString('"supervision_validated":false', $report);
        $this->assertStringContainsString('"alert_dispatched":false', $report);
        $this->assertStringNotContainsString('sensitive-sentinel', $report);
        $this->assertStringNotContainsString(storage_path(), $report);
    }

    public function test_ready_wait_threshold_warns_but_delayed_and_reserved_jobs_do_not(): void
    {
        $this->freezeTime();
        $this->configureDatabaseProbes();
        $this->insertJob(['available_at' => now()->getTimestamp() - 299]);
        $this->insertJob(['available_at' => now()->getTimestamp() - 1000, 'reserved_at' => now()->getTimestamp() - 900]);
        $this->insertJob(['available_at' => now()->getTimestamp() + 900]);
        $beforeThreshold = app(RuntimeDiagnostics::class)->inspect(true);
        $this->assertSame(0, $beforeThreshold->exitCode());

        $this->insertJob(['available_at' => now()->getTimestamp() - 300]);
        $inspection = app(RuntimeDiagnostics::class)->inspect(true);

        $this->assertSame(1, $inspection->exitCode());
        $this->assertSame('QUEUE_READY_WAIT_REVIEW', $inspection->checks['queues']->code);
        $this->assertSame([
            'sampled' => 4, 'ready_observed' => 2, 'reserved_observed' => 1,
            'delayed_observed' => 1, 'oldest_ready_age_seconds_observed' => 300,
        ], $inspection->checks['queues']->observations['queues']['default']);
        $this->assertFalse($inspection->checks['queues']->observations['worker_liveness_verified']);
        $this->assertDatabaseCount('jobs', 4);
    }

    public function test_truncated_sample_cannot_claim_all_queues_passed(): void
    {
        $this->configureDatabaseProbes();
        config(['operations.monitoring.queue_sample_limit' => 1]);
        $this->insertJob(['available_at' => now()->getTimestamp() + 900]);
        $this->insertJob(['available_at' => now()->getTimestamp() - 1000]);

        $inspection = app(RuntimeDiagnostics::class)->inspect(true);

        $this->assertSame(2, $inspection->exitCode());
        $this->assertSame('QUEUE_SAMPLE_TRUNCATED', $inspection->checks['queues']->code);
        $this->assertTrue($inspection->checks['queues']->observations['truncated']);
        $this->assertSame(1, $inspection->checks['queues']->observations['queues']['default']['sampled']);
    }

    public function test_historical_failures_are_scoped_bounded_and_require_review_without_claiming_outage(): void
    {
        $this->configureDatabaseProbes();
        config(['operations.monitoring.queue_sample_limit' => 1]);
        foreach ([['redis', 'default'], ['database', 'unregistered']] as [$connection, $queue]) {
            DB::table('failed_jobs')->insert([
                'uuid' => fake()->uuid(), 'connection' => $connection, 'queue' => $queue,
                'payload' => 'sensitive-sentinel', 'exception' => 'sensitive-sentinel', 'failed_at' => now()->subYear(),
            ]);
        }
        $this->assertSame(ProbeState::Passed, app(RuntimeProbe::class)->failedJobs(1)->state);
        foreach (['default', 'reports'] as $queue) {
            DB::table('failed_jobs')->insert([
                'uuid' => fake()->uuid(), 'connection' => 'database', 'queue' => $queue,
                'payload' => 'sensitive-sentinel', 'exception' => 'sensitive-sentinel', 'failed_at' => now()->subYear(),
            ]);
        }

        $inspection = app(RuntimeDiagnostics::class)->inspect(true);

        $this->assertSame(1, $inspection->exitCode());
        $check = $inspection->checks['failed_jobs'];
        $this->assertSame('FAILED_JOBS_HISTORY_REVIEW', $check->code);
        $this->assertSame(1, array_sum($check->observations['observed']));
        $this->assertTrue($check->observations['truncated']);
        $this->assertFalse($check->observations['current_outage_proven']);
        $this->assertStringNotContainsString('sensitive-sentinel', json_encode($inspection->toArray(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('failed_jobs', 4);
    }

    public function test_unsupported_drivers_remain_unverified_without_connecting(): void
    {
        config(['queue.default' => 'sync', 'queue.failed.driver' => 'null', 'cache.default' => 'unknown',
            'cache.stores.unknown.driver' => 'custom', 'filesystems.artifacts' => 's3']);

        $inspection = app(RuntimeDiagnostics::class)->inspect(true);

        $this->assertSame(2, $inspection->exitCode());
        $this->assertSame('QUEUE_DRIVER_UNSUPPORTED', $inspection->checks['queues']->code);
        $this->assertSame('FAILED_JOBS_DRIVER_UNSUPPORTED', $inspection->checks['failed_jobs']->code);
        $this->assertSame('CACHE_DRIVER_UNSUPPORTED', $inspection->checks['cache']->code);
        $this->assertSame('STORAGE_DRIVER_UNSUPPORTED', $inspection->checks['private_storage']->code);
    }

    public function test_redis_totals_do_not_claim_job_age_or_worker_liveness(): void
    {
        config(['queue.default' => 'redis']);
        $queue = Mockery::mock(Queue::class);
        foreach (QueueName::orderedValues() as $name) {
            $queue->shouldReceive('size')->once()->with($name)->andReturn(3);
        }
        $this->mock(QueueFactory::class)->shouldReceive('connection')->once()->with('redis')->andReturn($queue);

        $check = app(RuntimeProbe::class)->queues(1000, 300);

        $this->assertSame(ProbeState::Unverified, $check->state);
        $this->assertSame('QUEUE_TOTALS_ONLY', $check->code);
        $this->assertSame(15, array_sum($check->observations['totals']));
        $this->assertFalse($check->observations['oldest_ready_age_verified']);
        $this->assertFalse($check->observations['worker_liveness_verified']);
    }

    #[DataProvider('inconclusiveCacheDrivers')]
    public function test_memory_and_file_caches_cannot_validate_a_shared_cache(string $store): void
    {
        config(['cache.default' => $store, 'cache.stores.file.path' => '/sensitive-sentinel/missing']);

        $check = app(RuntimeProbe::class)->cache();

        $this->assertSame(ProbeState::Unverified, $check->state);
        $this->assertSame('CACHE_READ_INCONCLUSIVE', $check->code);
    }

    /** @return array<string, array{string}> */
    public static function inconclusiveCacheDrivers(): array
    {
        return ['memory' => ['array'], 'file' => ['file']];
    }

    #[DataProvider('components')]
    public function test_dependency_failures_are_redacted_and_other_checks_continue(string $failingMethod, string $component): void
    {
        $logger = Log::spy();
        $probe = $this->mock(RuntimeProbe::class);
        foreach (['database', 'cache', 'queues', 'failedJobs', 'privateStorage'] as $method) {
            $expectation = $probe->shouldReceive($method)->once();
            if ($method === $failingMethod) {
                $expectation->andThrow(new RuntimeException('sensitive-sentinel host password /srv/private'));
            } else {
                $expectation->andReturn(new RuntimeCheck(ProbeState::Passed, 'READ_PASSED'));
            }
        }

        $this->assertSame(1, Artisan::call('ops:inspect-runtime', ['--probe' => true, '--json' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString(strtoupper($component).'_READ_FAILED', $output);
        $this->assertStringNotContainsString('sensitive-sentinel', $output);
        $this->assertStringNotContainsString('/srv/private', $output);
        $logger->shouldNotHaveReceived('error');
        $logger->shouldNotHaveReceived('warning');
    }

    /** @return array<string, array{string, string}> */
    public static function components(): array
    {
        return ['database' => ['database', 'database'], 'cache' => ['cache', 'cache'],
            'queues' => ['queues', 'queues'], 'failed' => ['failedJobs', 'failed_jobs'], 'storage' => ['privateStorage', 'private_storage']];
    }

    #[DataProvider('invalidMonitoringConfiguration')]
    public function test_invalid_limits_fail_before_any_probe(string $key, mixed $value): void
    {
        config([$key => $value]);
        $probe = $this->mock(RuntimeProbe::class);
        foreach (['database', 'cache', 'queues', 'failedJobs', 'privateStorage'] as $method) {
            $probe->shouldNotReceive($method);
        }

        $inspection = app(RuntimeDiagnostics::class)->inspect(true);

        $this->assertSame(1, $inspection->exitCode());
        $this->assertSame('MONITORING_CONFIGURATION_INVALID', $inspection->checks['configuration']->code);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidMonitoringConfiguration(): array
    {
        return ['zero sample' => ['operations.monitoring.queue_sample_limit', 0],
            'huge sample' => ['operations.monitoring.queue_sample_limit', 10001],
            'string sample' => ['operations.monitoring.queue_sample_limit', 'sensitive-sentinel'],
            'zero warning' => ['operations.monitoring.ready_wait_warning_seconds', 0],
            'huge warning' => ['operations.monitoring.ready_wait_warning_seconds', 86401],
            'string warning' => ['operations.monitoring.ready_wait_warning_seconds', '300']];
    }

    public function test_public_and_missing_storage_roots_are_rejected_without_exposing_paths(): void
    {
        config(['filesystems.artifacts' => 'local', 'filesystems.disks.local.root' => public_path()]);
        $this->assertSame('PRIVATE_STORAGE_PUBLIC_ROOT', app(RuntimeProbe::class)->privateStorage()->code);
        config(['filesystems.disks.local.root' => storage_path('sensitive-sentinel-not-existing')]);
        $check = app(RuntimeProbe::class)->privateStorage();
        $this->assertSame('PRIVATE_STORAGE_ROOT_UNREADABLE', $check->code);
        $this->assertStringNotContainsString('sensitive-sentinel', json_encode($check->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_public_visibility_is_rejected_even_outside_public_directory(): void
    {
        $disk = Storage::fake('ops-private');
        config(['filesystems.artifacts' => 'ops-private', 'filesystems.disks.ops-private.driver' => 'local',
            'filesystems.disks.ops-private.root' => $disk->path(''), 'filesystems.disks.ops-private.visibility' => 'public']);

        $this->assertSame('PRIVATE_STORAGE_PUBLIC_ROOT', app(RuntimeProbe::class)->privateStorage()->code);
    }

    public function test_invalid_table_name_fails_without_running_injected_sql(): void
    {
        $this->configureDatabaseProbes();
        config(['queue.connections.database.table' => 'jobs; drop table jobs; sensitive-sentinel']);

        $inspection = app(RuntimeDiagnostics::class)->inspect(true);

        $this->assertSame('QUEUES_READ_FAILED', $inspection->checks['queues']->code);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertStringNotContainsString('sensitive-sentinel', json_encode($inspection->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_real_query_failure_is_redacted_while_other_reads_complete(): void
    {
        $this->configureDatabaseProbes();
        config(['database.connections.ops_probe_missing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'queue.connections.database.connection' => 'ops_probe_missing',
            'queue.connections.database.table' => 'missing_sensitive_sentinel']);

        try {
            $inspection = app(RuntimeDiagnostics::class)->inspect(true);
        } finally {
            DB::purge('ops_probe_missing');
        }

        $this->assertSame(1, $inspection->exitCode());
        $this->assertSame('QUEUES_READ_FAILED', $inspection->checks['queues']->code);
        $this->assertSame(ProbeState::Passed, $inspection->checks['database']->state);
        $this->assertSame(ProbeState::Passed, $inspection->checks['failed_jobs']->state);
        $this->assertStringNotContainsString('sensitive_sentinel', json_encode($inspection->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_storage_root_cannot_include_the_public_application_directory(): void
    {
        config(['filesystems.artifacts' => 'local', 'filesystems.disks.local.root' => base_path()]);

        $this->assertSame('PRIVATE_STORAGE_PUBLIC_ROOT', app(RuntimeProbe::class)->privateStorage()->code);
    }

    private function configureDatabaseProbes(): void
    {
        $disk = Storage::fake('ops-private');
        config(['queue.default' => 'database', 'queue.connections.database.connection' => null,
            'queue.failed.database' => config('database.default'), 'queue.failed.driver' => 'database-uuids',
            'cache.default' => 'database', 'cache.stores.database.connection' => null,
            'filesystems.artifacts' => 'ops-private', 'filesystems.disks.ops-private.driver' => 'local',
            'filesystems.disks.ops-private.root' => $disk->path('')]);
    }

    /** @param array<string, int|null> $metadata */
    private function insertJob(array $metadata): void
    {
        DB::table('jobs')->insert(array_merge([
            'queue' => 'default', 'payload' => 'sensitive-sentinel', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp(),
        ], $metadata));
    }
}
