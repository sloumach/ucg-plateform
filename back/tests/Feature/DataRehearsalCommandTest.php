<?php

namespace Tests\Feature;

use App\Modules\Reporting\Domain\Contracts\DataRehearsalRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class DataRehearsalCommandTest extends TestCase
{
    public function test_complete_fictitious_evidence_is_reported_without_real_import_or_business_signoff(): void
    {
        $result = $this->evidence();
        $result['checks'][0]['facts']['PRIVATE_SOURCE_CELL'] = 7;
        $runner = $this->mock(DataRehearsalRunner::class);
        $runner->shouldReceive('run')->once()->andReturn($result);
        $this->assertSame(0, Artisan::call('data:rehearse'));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('fictitious_rehearsal_passed', $report['status']);
        $this->assertTrue($report['rollback_verified']);
        $this->assertSame(2, $report['checks']['pipeline']['people_created']);
        foreach (['data002_closed', 'business_signature_verified', 'real_data_processed', 'import_authorized'] as $flag) {
            $this->assertFalse($report[$flag]);
        }
        $this->assertContains('real_source_reconciliation', $report['not_verified']);
        $this->assertStringNotContainsString('PRIVATE', $output);
    }

    /** @return array<string,array{string}> */
    public static function incompleteEvidence(): array
    {
        return ['failed process' => ['exit'], 'missing case' => ['tests'], 'failed case' => ['failure'], 'errored case' => ['error'],
            'skipped case' => ['skip'], 'no assertions' => ['assertions'], 'missing proof' => ['missing'], 'duplicate proof' => ['duplicate'],
            'wrong totals' => ['totals'], 'rollback failed' => ['rollback'], 'artifacts left' => ['artifacts'],
            'unknown proof' => ['unknown'], 'invalid hash' => ['hash']];
    }

    #[DataProvider('incompleteEvidence')]
    public function test_failure_or_incomplete_evidence_never_becomes_a_success(string $variant): void
    {
        $result = $this->evidence();
        match ($variant) {
            'exit' => $result['exit_code'] = 1,
            'tests' => $result['tests'] = 4,
            'failure' => $result['failures'] = 1,
            'error' => $result['errors'] = 1,
            'skip' => $result['skipped'] = 1,
            'assertions' => $result['assertions'] = 0,
            'missing' => array_pop($result['checks']),
            'duplicate' => $result['checks'][] = $result['checks'][0],
            'totals' => $result['checks'][0]['facts']['people_created'] = 1,
            'rollback' => $result['checks'][0]['facts']['baseline_restored'] = false,
            'artifacts' => $result['checks'][0]['facts']['artifacts_restored'] = false,
            'unknown' => $result['checks'][0]['check'] = 'PRIVATE_UNKNOWN_CHECK',
            'hash' => $result['scenario_sha256'] = 'PRIVATE_NOT_A_HASH',
            default => throw new RuntimeException('Unknown evidence variant.'),
        };
        $runner = $this->mock(DataRehearsalRunner::class);
        $runner->shouldReceive('run')->once()->andReturn($result);
        $this->assertSame(2, Artisan::call('data:rehearse'));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('rehearsal_failed', $report['status']);
        $this->assertFalse($report['rollback_verified']);
        $this->assertFalse($report['import_authorized']);
        $this->assertStringNotContainsString('PRIVATE', $output);
    }

    public function test_unexpected_runner_failure_does_not_expose_paths_or_source_values(): void
    {
        $runner = $this->mock(DataRehearsalRunner::class);
        $runner->shouldReceive('run')->once()->andThrow(new RuntimeException('PRIVATE_SOURCE_PATH_AND_VALUE'));
        $logger = $this->mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with('data.rehearsal.failed', ['exception_type' => RuntimeException::class]);
        $this->assertSame(1, Artisan::call('data:rehearse'));
        $output = Artisan::output();
        $this->assertStringNotContainsString('PRIVATE', $output);
        $this->assertStringContainsString('Aucun import réel autorisé', $output);
    }

    public function test_real_child_process_ignores_parent_database_configuration_and_never_queries_it(): void
    {
        Storage::fake('data002-parent');
        $sentinel = Storage::disk('data002-parent')->path('PRIVATE_UNUSED.sqlite');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $sentinel]);
        DB::purge('sqlite');
        $queries = [];
        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        Storage::disk('data002-parent')->put('PRIVATE_CONFIG.php', '<?php throw new RuntimeException("PRIVATE_CACHE_EXECUTED");');
        $hostile = ['DB_URL' => 'pgsql://invalid.test/PRIVATE_UNUSED_DATABASE', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $sentinel,
            'TENANCY_CACHE_STORE' => 'redis', 'APP_CONFIG_CACHE' => Storage::disk('data002-parent')->path('PRIVATE_CONFIG.php')];
        $previous = [];
        foreach ($hostile as $key => $value) {
            $previous[$key] = ['process' => getenv($key), 'env' => $_ENV[$key] ?? null, 'server' => $_SERVER[$key] ?? null];
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        try {
            $this->assertSame(0, Artisan::call('data:rehearse'));
        } finally {
            foreach ($previous as $key => $values) {
                $values['process'] === false ? putenv($key) : putenv($key.'='.$values['process']);
                if ($values['env'] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $values['env'];
                }
                if ($values['server'] === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $values['server'];
                }
            }
        }
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('fictitious_rehearsal_passed', $report['status']);
        $this->assertSame([], $queries);
        $this->assertFileDoesNotExist($sentinel);
        $this->assertCount(5, $report['checks']);
        $this->assertStringNotContainsString('PRIVATE', $output);
    }

    /** @return array{exit_code:int,tests:int,failures:int,errors:int,skipped:int,assertions:int,checks:list<array{check:string,facts:array<string,int|bool>}>,scenario_sha256:string} */
    private function evidence(): array
    {
        $facts = [
            'pipeline' => ['people_created' => 2, 'teams_created' => 1, 'matches_created' => 1, 'contracts_created' => 1, 'observations_created' => 2, 'samples_checked' => 4, 'links_checked' => 5],
            'idempotency' => ['people_created' => 2, 'observations_created' => 1, 'duplicates_skipped' => 1],
            'invalid_rows' => ['invalid_rows' => 2, 'conflicts_rejected' => 1, 'observations_created' => 1],
            'tenant_isolation' => ['denied_requests' => 3, 'out_of_tenant_writes' => 0],
            'interruption' => ['partial_rows' => 1, 'interruption_detected' => true],
        ];
        $checks = [];
        foreach ($facts as $name => $values) {
            $checks[] = ['check' => $name, 'facts' => [...$values, 'baseline_restored' => true, 'artifacts_restored' => true]];
        }

        return ['exit_code' => 0, 'tests' => 5, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'assertions' => 100, 'checks' => $checks, 'scenario_sha256' => str_repeat('a', 64)];
    }
}
