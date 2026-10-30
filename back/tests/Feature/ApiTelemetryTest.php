<?php

namespace Tests\Feature;

use App\Contracts\Operations\ApiMetrics;
use App\Support\Operations\ApiMeasurement;
use App\Support\Operations\ProbeState;
use App\Support\Operations\SanitizeOperationsLog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class ApiTelemetryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_disabled_collection_does_not_open_a_database(): void
    {
        config(['operations.api.enabled' => false]);
        Route::get('/api/ops-test', static fn () => response()->json(['ok' => true]));
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson('/api/ops-test')->assertOk()->assertHeader('X-Request-ID');

        $this->assertSame(0, $queries);
    }

    public function test_http_collection_preserves_response_and_omits_request_contents(): void
    {
        $this->configureMetrics();
        Route::post('/api/ops-test/{private}', static fn () => response()->json(['ok' => true], 201));

        $response = $this->withHeaders(['Authorization' => 'Bearer sensitive-sentinel', 'X-Request-ID' => 'sensitive-sentinel'])
            ->postJson('/api/ops-test/sensitive-sentinel?email=sensitive-sentinel', ['password' => 'sensitive-sentinel']);

        $response->assertCreated()->assertExactJson(['ok' => true]);
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
        $check = app(ApiMetrics::class)->inspect();
        $this->assertSame(1, $check->observations['observed']['total']);
        $this->assertSame(0, $check->observations['observed']['errors']);
        $this->assertStringNotContainsString('sensitive-sentinel', DB::table('cache')->get()->toJson());
    }

    public function test_api_errors_and_slow_request_thresholds_are_measured_without_percentile_claims(): void
    {
        $this->configureMetrics();
        $metrics = app(ApiMetrics::class);
        $metrics->record(new ApiMeasurement((string) Str::uuid(), 500, 10));
        $this->assertSame(ProbeState::Unverified, $metrics->inspect()->state);
        $metrics->record(new ApiMeasurement((string) Str::uuid(), 200, 1000));

        $check = $metrics->inspect();

        $this->assertSame('API_ERROR_OR_LATENCY_REVIEW', $check->code);
        $this->assertSame(['total' => 2, 'errors' => 1, 'slow' => 1, 'duration_ms' => 1010], $check->observations['observed']);
        $this->assertFalse($check->observations['percentiles_verified']);
    }

    public function test_old_samples_and_other_namespaces_do_not_make_a_quiet_api_healthy(): void
    {
        $this->configureMetrics();
        $metrics = app(ApiMetrics::class);
        $metrics->record(new ApiMeasurement((string) Str::uuid(), 200, 1));
        $metrics->record(new ApiMeasurement((string) Str::uuid(), 200, 1));
        $this->assertSame(ProbeState::Passed, $metrics->inspect()->state);
        config(['operations.resource_namespace' => 'ops_other']);
        $otherNamespaceCode = $metrics->inspect()->code;
        config(['operations.resource_namespace' => 'ops_api_test']);
        $this->travel(5)->minutes();
        $expiredCode = $metrics->inspect()->code;
        $this->assertSame(['API_SAMPLES_INSUFFICIENT', 'API_SAMPLES_INSUFFICIENT'], [$otherNamespaceCode, $expiredCode]);
    }

    public function test_partial_cache_eviction_cannot_report_a_healthy_window(): void
    {
        $this->configureMetrics();
        $metrics = app(ApiMetrics::class);
        $metrics->record(new ApiMeasurement((string) Str::uuid(), 500, 1));
        Cache::store('database')->forget('ops:ops_api_test:api:'.intdiv(now()->getTimestamp(), 60).':errors');

        $this->assertSame('API_COUNTER_INCOMPLETE', $metrics->inspect()->code);
    }

    public function test_error_log_budget_is_bounded_per_minute(): void
    {
        $this->configureMetrics();
        config(['operations.api.log_limit_per_minute' => 1]);
        $metrics = app(ApiMetrics::class);

        $this->assertTrue($metrics->record(new ApiMeasurement((string) Str::uuid(), 500, 1)));
        $this->assertFalse($metrics->record(new ApiMeasurement((string) Str::uuid(), 500, 1)));
        $this->travel(1)->minutes();
        $this->assertTrue($metrics->record(new ApiMeasurement((string) Str::uuid(), 500, 1)));
    }

    public function test_telemetry_failure_does_not_change_http_error_response(): void
    {
        $this->configureMetrics();
        $this->mock(ApiMetrics::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('sensitive-sentinel'));
        Route::get('/api/ops-error', static fn () => response()->json(['code' => 'FIXTURE'], 503));

        $this->getJson('/api/ops-error')->assertStatus(503)->assertExactJson(['code' => 'FIXTURE']);
    }

    public function test_formatter_removes_context_and_extra_added_by_other_processors(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $monolog = new Monolog('operations', [new StreamHandler($stream)]);
        $logger = new Logger($monolog);
        (new SanitizeOperationsLog)($logger);
        $monolog->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(extra: ['tenant' => 'sensitive-sentinel']));
        $id = (string) Str::uuid();
        $logger->warning('sensitive-sentinel', ['request_id' => $id, 'status' => 503, 'duration_ms' => 2,
            'email' => 'sensitive-sentinel', 'exception' => new RuntimeException('sensitive-sentinel')]);
        rewind($stream);
        $output = stream_get_contents($stream);
        $this->assertIsString($output);
        $this->assertStringNotContainsString('sensitive-sentinel', $output);
        $record = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['request_id' => $id, 'status' => 503, 'duration_ms' => 2], $record['context']);
        fclose($stream);
    }

    #[TestWith(['bad', 200, 1])]
    #[TestWith(['00000000-0000-4000-8000-000000000001', 600, 1])]
    #[TestWith(['00000000-0000-4000-8000-000000000001', 200, -1])]
    public function test_invalid_measurements_are_refused(string $id, int $status, int $duration): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiMeasurement($id, $status, $duration);
    }

    private function configureMetrics(): void
    {
        $this->freezeTime();
        config(['operations.api.enabled' => true, 'operations.api.cache_store' => 'database',
            'operations.resource_namespace' => 'ops_api_test', 'operations.api.minimum_samples' => 2,
            'operations.api.log_limit_per_minute' => 0, 'cache.stores.database.connection' => null]);
    }
}
