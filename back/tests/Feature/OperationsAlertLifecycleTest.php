<?php

namespace Tests\Feature;

use App\Support\Operations\AlertService;
use App\Support\Operations\ProbeState;
use App\Support\Operations\RuntimeCheck;
use App\Support\Operations\RuntimeInspection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class OperationsAlertLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_alerts_deduplicate_and_escalate_once_until_acknowledged(): void
    {
        $this->configureLedger();
        $service = app(AlertService::class);
        $this->observe(ProbeState::Failed);
        $this->observe(ProbeState::Failed);
        $this->assertDatabaseCount('operations_alerts', 1);
        $this->assertDatabaseCount('operations_alert_events', 1);
        $this->travel(1800)->seconds();
        $this->observe(ProbeState::Failed);
        $this->observe(ProbeState::Failed);
        $row = $service->listing(1)[0];
        $this->assertSame(2, (int) $row['version']);
        $this->assertNotNull($row['escalated_at']);
        $this->assertDatabaseCount('operations_alert_events', 2);
        $service->acknowledge($row['id'], (int) $row['version'], (string) Str::uuid());
        $this->observe(ProbeState::Failed);
        $this->assertSame('acknowledged', $service->listing(1)[0]['status']);
        $this->assertDatabaseCount('operations_alert_events', 3);
    }

    public function test_incomplete_observation_cannot_resolve_an_alert_but_healthy_read_can_and_failure_reopens(): void
    {
        $this->configureLedger();
        $this->observe(ProbeState::Warning);
        $this->observe(ProbeState::Unverified);
        $this->assertSame('open', app(AlertService::class)->listing(1)[0]['status']);
        $this->observe(ProbeState::Passed);
        $this->observe(ProbeState::Passed);
        $this->assertSame('resolved', app(AlertService::class)->listing(1)[0]['status']);
        $this->observe(ProbeState::Warning);
        $this->assertSame('open', app(AlertService::class)->listing(1)[0]['status']);
        $this->assertSame(['opened', 'resolved', 'reopened'], DB::table('operations_alert_events')->orderBy('id')->pluck('action')->all());
        $this->assertDatabaseCount('operations_alerts', 1);
    }

    public function test_changed_error_resets_acknowledgement_and_stale_version_is_refused(): void
    {
        $this->configureLedger();
        $this->observe(ProbeState::Warning);
        $service = app(AlertService::class);
        $row = $service->listing(1)[0];
        $service->acknowledge($row['id'], 1, (string) Str::uuid());
        $this->observe(ProbeState::Failed, 'READ_FAILED');
        $this->assertSame('open', $service->listing(1)[0]['status']);
        $this->assertSame(3, (int) $service->listing(1)[0]['version']);
        $this->expectExceptionMessage('ALERT_VERSION_CONFLICT');
        $service->acknowledge($row['id'], 1, (string) Str::uuid());
    }

    public function test_namespaces_and_incident_references_are_scoped(): void
    {
        $this->configureLedger();
        $this->observe(ProbeState::Warning);
        $row = app(AlertService::class)->listing(1)[0];
        config(['operations.resource_namespace' => 'ops_other']);
        $this->assertSame([], app(AlertService::class)->listing(1));
        $this->expectExceptionMessage('ALERT_NOT_FOUND');
        app(AlertService::class)->acknowledge($row['id'], 1, (string) Str::uuid());
    }

    public function test_acknowledgement_command_requires_confirmation_and_valid_reference(): void
    {
        $this->configureLedger();
        $this->observe(ProbeState::Failed);
        $row = app(AlertService::class)->listing(1)[0];
        $this->assertSame(2, Artisan::call('ops:alerts', ['action' => 'acknowledge', 'id' => $row['id'], '--expected-version' => 1]));
        $this->assertSame(1, Artisan::call('ops:alerts', ['action' => 'acknowledge', 'id' => $row['id'],
            '--expected-version' => 1, '--incident' => 'sensitive-sentinel', '--confirm' => true]));
        $this->assertStringNotContainsString('sensitive-sentinel', Artisan::output());
        $this->assertSame('open', app(AlertService::class)->listing(1)[0]['status']);
        $incident = (string) Str::uuid();
        $this->assertSame(0, Artisan::call('ops:alerts', ['action' => 'acknowledge', 'id' => $row['id'],
            '--expected-version' => 1, '--incident' => $incident, '--confirm' => true]));
        $this->assertDatabaseHas('operations_alert_events', ['action' => 'acknowledged', 'incident_id' => $incident]);
        $this->assertSame(0, Artisan::call('ops:alerts', ['--probe' => true, '--json' => true]));
        $this->assertStringContainsString('acknowledged', Artisan::output());
    }

    public function test_disabled_ledger_and_missing_probe_do_not_access_the_database(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        config(['operations.alerts.enabled' => false]);
        $disabled = Artisan::call('ops:alerts');
        config(['operations.alerts.enabled' => true]);
        $missingProbe = Artisan::call('ops:alerts');
        $this->assertSame([2, 2], [$disabled, $missingProbe]);
        $this->assertSame(2, Artisan::call('ops:inspect-supervision', ['--record-alerts' => true]));
        $this->assertSame(0, $queries);
    }

    public function test_invalid_report_is_rejected_atomically_without_private_codes(): void
    {
        $this->configureLedger();
        try {
            app(AlertService::class)->synchronize(new RuntimeInspection([
                'api_http' => new RuntimeCheck(ProbeState::Failed, 'SAFE_FAILURE'),
                'sensitive-sentinel' => new RuntimeCheck(ProbeState::Failed, 'password'),
            ], true));
            $this->fail('Expected invalid report rejection.');
        } catch (LogicException $exception) {
            $this->assertSame('ALERT_REPORT_INVALID', $exception->getMessage());
        }
        $this->assertDatabaseCount('operations_alerts', 0);
        $this->assertDatabaseCount('operations_alert_events', 0);
    }

    public function test_listing_is_bounded_and_does_not_return_other_namespace_or_observations(): void
    {
        $this->configureLedger();
        $checks = [];
        for ($index = 0; $index < 55; $index++) {
            $checks['fixture_'.$index] = new RuntimeCheck(ProbeState::Warning, 'REVIEW_REQUIRED', ['private' => 'sensitive-sentinel']);
        }
        app(AlertService::class)->synchronize(new RuntimeInspection($checks, true));
        $this->assertCount(50, app(AlertService::class)->listing(1));
        $this->assertCount(5, app(AlertService::class)->listing(2));
        $this->assertStringNotContainsString('sensitive-sentinel', json_encode(app(AlertService::class)->listing(1), JSON_THROW_ON_ERROR));
    }

    public function test_migration_refuses_to_discard_nonempty_alert_history(): void
    {
        $this->configureLedger();
        $this->observe(ProbeState::Warning);
        $migration = require database_path('migrations/2026_10_30_143449_create_operations_alert_tables.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    public function test_alert_history_cannot_be_rewritten_or_deleted(string $operation): void
    {
        $this->configureLedger();
        $this->observe(ProbeState::Warning);
        $query = DB::table('operations_alert_events');
        $this->expectException(QueryException::class);
        if ($operation === 'update') {
            $query->update(['action' => 'resolved']);
        } else {
            $query->delete();
        }
    }

    private function configureLedger(): void
    {
        $this->freezeTime();
        config(['operations.alerts.enabled' => true, 'operations.resource_namespace' => 'ops_ledger_test']);
    }

    private function observe(ProbeState $state, string $code = 'FIXTURE_REVIEW'): void
    {
        app(AlertService::class)->synchronize(new RuntimeInspection(['api_http' => new RuntimeCheck($state, $code)], true));
    }
}
