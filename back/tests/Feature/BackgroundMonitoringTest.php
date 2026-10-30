<?php

namespace Tests\Feature;

use App\Modules\Contracts\Domain\Models\ContractReminderDelivery;
use App\Modules\Contracts\Domain\Models\ContractReminderPlan;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\Performance\Domain\Models\StatisticalImport;
use App\Modules\Performance\Domain\Models\StatisticalImportExecution;
use App\Support\Operations\BackgroundHeartbeatRepository;
use App\Support\Operations\BackgroundMonitor;
use App\Support\Operations\BackgroundObserver;
use App\Support\Operations\BackgroundSettings;
use App\Support\Operations\BackgroundWorkloadRepository;
use App\Support\Operations\ProbeState;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\Fixtures\ProvidesContractDocuments;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class BackgroundMonitoringTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesContractDocuments, ProvidesPeopleAccess;

    public function test_no_probe_and_disabled_monitoring_do_not_access_resources(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        config(['operations.background.enabled' => false]);
        Event::dispatch(new Looping('database', 'default'));
        app(BackgroundObserver::class)->schedulerTick();
        $this->assertSame(2, Artisan::call('ops:inspect-background', ['--probe' => true, '--json' => true]));
        $this->assertStringContainsString('BACKGROUND_MONITORING_DISABLED', Artisan::output());
        config(['operations.background.enabled' => true, 'operations.resource_namespace' => 'invalid sensitive sentinel']);
        $this->assertSame(2, Artisan::call('ops:inspect-background', ['--json' => true]));
        $this->assertStringContainsString('PROBE_NOT_REQUESTED', Artisan::output());
        $this->assertSame([], $queries);
    }

    /** The daemon must not inherit the parent suite's memory usage or signal handlers. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_idle_database_worker_emits_activity_for_its_configured_queues(): void
    {
        $this->configureMonitoring();
        DB::table('jobs')->count();
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database',
            '--queue' => 'default,reports', '--stop-when-empty' => true, '--sleep' => 0]));

        $inspection = app(BackgroundMonitor::class)->inspect(true);

        $this->assertSame(ProbeState::Passed, $inspection->checks['activity_default']->state);
        $this->assertSame(ProbeState::Passed, $inspection->checks['activity_reports']->state);
        $this->assertSame('BACKGROUND_HEARTBEAT_MISSING', $inspection->checks['activity_scheduler']->code);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_scheduler_registers_and_runs_its_marker_only_when_enabled(): void
    {
        $this->configureMonitoring();
        // Console routes were booted with monitoring disabled when the test app was created.
        require base_path('routes/console.php');
        $this->assertSame(0, Artisan::call('schedule:list'));
        $events = array_values(array_filter(app(Schedule::class)->events(),
            fn ($event): bool => $event->description === 'ops-background-heartbeat'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);

        $events[0]->run(app());

        $this->assertSame('BACKGROUND_ACTIVITY_OBSERVED', app(BackgroundHeartbeatRepository::class)
            ->read(BackgroundSettings::from(config()), 'scheduler')->code);
    }

    public function test_disabled_scheduler_has_no_monitoring_task(): void
    {
        config(['operations.background.enabled' => false]);
        $this->assertSame(0, Artisan::call('schedule:list'));
        $this->assertSame([], array_values(array_filter(app(Schedule::class)->events(),
            fn ($event): bool => $event->description === 'ops-background-heartbeat')));
    }

    public function test_stale_activity_is_distinct_from_missing_and_fresh_activity(): void
    {
        $this->configureMonitoring();
        app(BackgroundObserver::class)->schedulerTick();
        Event::dispatch(new Looping('database', 'default'));
        $this->travel(179)->seconds();
        $this->assertSame('BACKGROUND_ACTIVITY_OBSERVED', app(BackgroundMonitor::class)->inspect(true)->checks['activity_default']->code);
        $this->travel(1)->seconds();

        $inspection = app(BackgroundMonitor::class)->inspect(true);

        $this->assertSame('BACKGROUND_ACTIVITY_STALE', $inspection->checks['activity_default']->code);
        $this->assertSame('BACKGROUND_ACTIVITY_STALE', $inspection->checks['activity_scheduler']->code);
        $this->assertSame(180, $inspection->checks['activity_default']->observations['age_seconds']);
        $this->assertSame('BACKGROUND_HEARTBEAT_MISSING', $inspection->checks['activity_reports']->code);
        Event::dispatch(new Looping('database', 'default,reports'));
        $this->assertSame('BACKGROUND_ACTIVITY_OBSERVED', app(BackgroundMonitor::class)->inspect(true)->checks['activity_reports']->code);
    }

    public function test_worker_markers_are_throttled_and_ignore_other_connections_and_queues(): void
    {
        $this->configureMonitoring();
        $settings = BackgroundSettings::from(config());
        Event::dispatch(new Looping('redis', 'default'));
        Event::dispatch(new Looping('database', 'foreign-sensitive-sentinel'));
        $this->assertDatabaseCount('cache', 0);
        Event::dispatch(new Looping('database', 'default,default'));
        $first = Cache::store('database')->get($settings->key('default'));
        $this->travel(29)->seconds();
        Event::dispatch(new Looping('database', 'default'));
        $this->assertSame($first, Cache::store('database')->get($settings->key('default')));
        $this->travel(1)->seconds();
        Event::dispatch(new Looping('database', 'default'));
        $this->assertSame(now()->getTimestamp(), Cache::store('database')->get($settings->key('default')));
        $this->assertDatabaseCount('cache', 1);
    }

    public function test_environment_namespace_keeps_activity_isolated(): void
    {
        $this->configureMonitoring();
        app(BackgroundObserver::class)->schedulerTick();
        config(['operations.resource_namespace' => 'other_synthetic_environment']);

        $this->assertSame('BACKGROUND_HEARTBEAT_MISSING', app(BackgroundMonitor::class)->inspect(true)->checks['activity_scheduler']->code);
    }

    public function test_future_and_malformed_markers_are_redacted(): void
    {
        $this->configureMonitoring();
        $settings = BackgroundSettings::from(config());
        Cache::store('database')->forever($settings->key('default'), ['password' => 'sensitive-sentinel']);
        Cache::store('database')->forever($settings->key('scheduler'), now()->getTimestamp() + 60);

        $inspection = app(BackgroundMonitor::class)->inspect(true);

        $this->assertSame('BACKGROUND_HEARTBEAT_INVALID', $inspection->checks['activity_default']->code);
        $this->assertSame('BACKGROUND_HEARTBEAT_INVALID', $inspection->checks['activity_scheduler']->code);
        $this->assertStringNotContainsString('sensitive-sentinel', json_encode($inspection->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_cache_failure_cannot_break_worker_or_scheduler_and_is_logged_without_exception_details(): void
    {
        $this->configureMonitoring();
        $this->mock(CacheFactory::class)->shouldReceive('store')->times(5)->with('database')
            ->andThrow(new RuntimeException('password sensitive-sentinel /srv/private'));
        $this->mock(LoggerInterface::class)->shouldReceive('warning')->twice()
            ->with('ops.background.marker_unavailable', \Mockery::on(fn (array $context): bool => in_array($context['component'], ['default', 'scheduler'], true)
                && $context['code'] === 'BACKGROUND_HEARTBEAT_WRITE_FAILED' && count($context) === 2));

        Event::dispatch(new Looping('database', 'default'));
        Event::dispatch(new Looping('database', 'default'));
        app(BackgroundObserver::class)->schedulerTick();

        $inspection = app(BackgroundMonitor::class)->inspect(true);
        $this->assertSame('BACKGROUND_HEARTBEAT_READ_FAILED', $inspection->checks['activity_default']->code);
        $this->assertSame(ProbeState::Passed, $inspection->checks['person_imports']->state);
        $this->assertStringNotContainsString('sensitive-sentinel', json_encode($inspection->toArray(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_recent_activity_and_empty_workloads_pass_only_the_selected_checks(): void
    {
        $this->configureMonitoring();
        app(BackgroundObserver::class)->schedulerTick();
        Event::dispatch(new Looping('database', 'default,reports'));

        $this->assertSame(0, Artisan::call('ops:inspect-background', ['--probe' => true, '--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([], $report['alert_candidates']);
        $this->assertFalse($report['supervision_validated']);
        $this->assertFalse($report['alert_dispatched']);
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_configuration_is_rejected_before_reads(string $key, mixed $value): void
    {
        $this->configureMonitoring();
        config([$key => $value]);
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $this->assertSame(1, Artisan::call('ops:inspect-background', ['--probe' => true, '--json' => true]));

        $this->assertStringContainsString('BACKGROUND_CONFIGURATION_INVALID', Artisan::output());
        $this->assertStringNotContainsString('sensitive-sentinel', Artisan::output());
        $this->assertSame([], $queries);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidConfiguration(): array
    {
        return ['namespace' => ['operations.resource_namespace', 'sensitive-sentinel/bad'],
            'local cache' => ['operations.background.cache_store', 'array'],
            'no workers' => ['operations.background.expected_queues', []],
            'duplicate queue' => ['operations.background.expected_queues', ['default', 'default']],
            'foreign queue' => ['operations.background.expected_queues', ['sensitive-sentinel']],
            'sample low' => ['operations.background.sample_limit', 0],
            'sample high' => ['operations.background.sample_limit', 10001],
            'heartbeat low' => ['operations.background.heartbeat_warning_seconds', 59],
            'work high' => ['operations.background.work_warning_seconds', 86401],
            'string limit' => ['operations.background.sample_limit', '1000']];
    }

    public function test_import_progress_and_failures_are_observed_without_changing_records_or_reading_content(): void
    {
        $this->configureMonitoring();
        $person = PersonImport::factory()->create(['status' => 'queued', 'updated_at' => now()->subSeconds(900)]);
        PersonImport::factory()->create(['status' => 'completed', 'updated_at' => now()->subDay()]);
        $preview = (new StatisticalImport)->forceFill([
            'organization_id' => $person->organization_id, 'actor_user_id' => $person->actor_user_id,
            'artifact_id' => $person->artifact_id, 'fingerprint' => hash('sha256', 'sensitive-sentinel'),
            'delimiter' => ',', 'headers' => ['sensitive-sentinel'], 'preview' => [], 'row_count' => 1,
            'valid_count' => 1, 'invalid_count' => 0, 'request_id' => (string) Str::uuid(), 'created_at' => now(),
        ]);
        $preview->save();
        (new StatisticalImportExecution)->forceFill([
            'organization_id' => $person->organization_id, 'import_id' => $preview->id,
            'status' => 'failed', 'version' => (string) Str::uuid(), 'results' => [], 'processed_count' => 0,
            'failure_code' => 'sensitive-sentinel',
        ])->save();
        $before = [DB::table('person_imports')->get()->toJson(), DB::table('statistical_import_executions')->get()->toJson()];
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $inspection = app(BackgroundMonitor::class)->inspect(true);
        $observedQueries = $queries;

        $this->assertSame(1, $inspection->checks['person_imports']->observations['late_observed']);
        $this->assertSame(1, $inspection->checks['person_imports']->observations['sampled']);
        $this->assertSame(900, $inspection->checks['person_imports']->observations['oldest_active_update_age_seconds_observed']);
        $this->assertSame(1, $inspection->checks['statistical_imports']->observations['failed_or_uncertain_observed']);
        foreach ($observedQueries as $sql) {
            $this->assertStringStartsWith('select ', strtolower($sql));
            if (str_contains($sql, 'import') || str_contains($sql, 'reminder')) {
                $this->assertStringNotContainsString('*', $sql);
                foreach (['headers', 'results', 'payload', 'recipient', 'organization_id', 'failure_code'] as $privateColumn) {
                    $this->assertStringNotContainsString($privateColumn, $sql);
                }
                $this->assertStringContainsString('limit 1001', $sql);
            }
        }
        $this->assertSame($before, [DB::table('person_imports')->get()->toJson(), DB::table('statistical_import_executions')->get()->toJson()]);
        $json = json_encode($inspection->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('sensitive-sentinel', $json);
        $this->assertStringNotContainsString($person->organization_id, $json);
        $this->assertStringNotContainsString($person->id, $json);
    }

    public function test_work_sample_truncation_and_recent_progress_do_not_produce_false_success(): void
    {
        $this->configureMonitoring();
        config(['operations.background.sample_limit' => 1]);
        PersonImport::factory()->create(['status' => 'queued', 'updated_at' => now()->subSeconds(899)]);
        PersonImport::factory()->create(['status' => 'queued', 'updated_at' => now()]);

        $check = app(BackgroundWorkloadRepository::class)->inspect('person_imports', BackgroundSettings::from(config()));

        $this->assertSame(ProbeState::Unverified, $check->state);
        $this->assertSame('BACKGROUND_WORK_SAMPLE_TRUNCATED', $check->code);
        $this->assertTrue($check->observations['truncated']);
        $this->assertSame(1, $check->observations['sampled']);
        $this->assertSame(0, $check->observations['late_observed']);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_missing_or_future_work_timestamps_require_review(bool $future): void
    {
        $this->configureMonitoring();
        $import = PersonImport::factory()->create(['status' => 'queued']);
        DB::table('person_imports')->where('id', $import->id)->update(['updated_at' => $future ? now()->addMinute() : null]);

        $check = app(BackgroundWorkloadRepository::class)->inspect('person_imports', BackgroundSettings::from(config()));

        $this->assertSame('BACKGROUND_WORK_REVIEW', $check->code);
        $this->assertTrue($check->observations['invalid_time_observed']);
        $this->assertSame(0, $check->observations['late_observed']);
    }

    public function test_pending_and_uncertain_reminders_require_review_but_blocked_and_sent_reminders_do_not(): void
    {
        $this->configureMonitoring();
        [$org, $user, $contract] = $this->documentScenario();
        $plan = (new ContractReminderPlan)->forceFill([
            'organization_id' => $org->id, 'contract_id' => $contract->id, 'enabled' => true,
            'offsets' => [0], 'channels' => ['email'], 'version' => (string) Str::uuid(), 'updated_by' => $user->id,
        ]);
        $plan->save();
        foreach (['pending', 'uncertain', 'blocked', 'sent'] as $status) {
            (new ContractReminderDelivery)->forceFill([
                'organization_id' => $org->id, 'contract_id' => $contract->id, 'plan_id' => $plan->id,
                'person_id' => $contract->responsible_person_id, 'recipient_id' => $user->id,
                'occurrence' => hash('sha256', $status), 'kind' => 'deadline', 'due_on' => now()->toDateString(),
                'offset_days' => 0, 'in_app_status' => 'not_requested', 'email_status' => $status,
                'attempts' => 0, 'version' => (string) Str::uuid(), 'run_id' => (string) Str::uuid(),
                'created_at' => now()->subSeconds(900), 'updated_at' => now()->subSeconds(900),
            ])->save();
        }

        $inspection = app(BackgroundMonitor::class)->inspect(true);
        $check = $inspection->checks['contract_reminders'];

        $this->assertSame('BACKGROUND_WORK_REVIEW', $check->code);
        $this->assertSame(2, $check->observations['sampled']);
        $this->assertSame(1, $check->observations['late_observed']);
        $this->assertSame(1, $check->observations['failed_or_uncertain_observed']);
        $this->assertDatabaseCount('contract_reminder_deliveries', 4);
    }

    public function test_alert_candidates_are_stable_and_never_dispatched(): void
    {
        $this->configureMonitoring();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $monitor = app(BackgroundMonitor::class);

        $report = $monitor->report($monitor->inspect(true));
        $again = $monitor->report($monitor->inspect(true));

        $this->assertCount(3, $report['alert_candidates']);
        $this->assertSame($report['alert_candidates'], $again['alert_candidates']);
        $this->assertFalse($report['alert_dispatched']);
        $this->assertFalse($report['supervision_validated']);
        foreach ($report['alert_candidates'] as $candidate) {
            $this->assertSame('prepared_only', $candidate['delivery']);
            $this->assertSame('OPS-003-BG', $candidate['procedure']);
            $this->assertSame('infrastructure_operator_to_designate', $candidate['responsible_role']);
        }
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    private function configureMonitoring(): void
    {
        $this->freezeTime();
        config(['operations.background.enabled' => true, 'operations.background.cache_store' => 'database',
            'operations.resource_namespace' => 'ops_synthetic', 'operations.background.expected_queues' => ['default', 'reports'],
            'queue.default' => 'database', 'queue.connections.database.connection' => null,
            'cache.stores.database.connection' => null]);
    }
}
