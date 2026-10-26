<?php

namespace Tests\Feature;

use App\Modules\Performance\Application\Services\StatisticalImportConfirmation;
use App\Modules\Performance\Application\Services\StatisticalImportProcessor;
use App\Modules\Performance\Application\Services\StatisticalImportService;
use App\Modules\Performance\Domain\Models\StatisticalImportExecution;
use App\Modules\Performance\Infrastructure\Queue\ProcessStatisticalImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMetricCatalogue;
use Tests\Fixtures\ProvidesObservations;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class StatisticalImportConcurrencyTest extends TestCase
{
    use AuthenticatesWithMfa, ProvidesMetricCatalogue, ProvidesObservations, ProvidesPeopleAccess;

    /** @return array<string, array{bool}> */
    public static function executions(): array
    {
        return ['two files' => [false], 'same queued generation' => [true]];
    }

    #[DataProvider('executions')]
    public function test_two_imports_of_the_same_observation_create_only_one_record(bool $sameExecution): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real observation concurrency runs in the PostgreSQL CI selection.');
        }
        $original = DB::getDefaultConnection();
        $config = config('database.connections.pgsql');
        $admin = DB::connection()->getPdo();
        $database = 'ucg_match001_race_'.bin2hex(random_bytes(6));
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ucg_match001_go_'.bin2hex(random_bytes(12));
        $workers = [];
        try {
            $admin->exec('CREATE DATABASE "'.$database.'"');
            config(['database.connections.match001_race' => [...$config, 'url' => null, 'database' => $database]]);
            DB::setDefaultConnection('match001_race');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
            $this->freezeTime();
            config(['statistical_imports.processing_enabled' => true]);
            $s = $this->observationScenario();
            $role = $s['assignment']->role;
            $role->forceFill(['permissions' => [...$role->permissions, 'sports.statistics.import']])->save();
            Queue::fake([ProcessStatisticalImport::class]);
            Storage::fake('local');
            $context = $this->personContext($s['org'], $s['writer']);
            $runs = [];
            foreach ($sameExecution ? [','] : [',', ';'] as $delimiter) {
                $stream = fopen('php://temp', 'w+');
                if ($stream === false) {
                    throw new \RuntimeException('Cannot allocate CSV concurrency fixture.');
                }
                fputcsv($stream, array_keys($s['input']), $delimiter, '"', '');
                fputcsv($stream, array_values($s['input']), $delimiter, '"', '');
                rewind($stream);
                $contents = stream_get_contents($stream);
                fclose($stream);
                $run = app(StatisticalImportService::class)->upload($context, $contents, $delimiter);
                app(StatisticalImportConfirmation::class)->confirm($context, $run->id, ['version' => $run->id, 'confirm' => true]);
                $runs[] = ['id' => $run->id, 'version' => StatisticalImportExecution::query()->where('import_id', $run->id)->firstOrFail()->version];
            }
            Queue::assertPushed(ProcessStatisticalImport::class, $sameExecution ? 1 : 2);
            $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'STATISTICAL_IMPORT_PROCESSING_ENABLED' => 'true'];
            for ($i = 0; $i < 2; $i++) {
                $payload = ['operation' => 'statistical_import', 'org' => $s['org']->id, 'actor' => $s['writer']->id,
                    'import' => $runs[$sameExecution ? 0 : $i]['id'], 'version' => $runs[$sameExecution ? 0 : $i]['version'], 'now' => CarbonImmutable::now()->toIso8601String()];
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/match-worker.php'), json_encode($payload, JSON_THROW_ON_ERROR), $barrier], base_path(), $environment, null, 60);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 20;
            do {
                $ready = true;
                foreach ($workers as $worker) {
                    if (! str_contains($worker->getOutput(), 'READY')) {
                        if (! $worker->isRunning()) {
                            $this->fail($worker->getErrorOutput().$worker->getOutput());
                        }
                        $ready = false;
                    }
                }
                if ($ready) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $this->fail('Both independent observation workers must reach the barrier.');
                }
                usleep(10000);
            } while (true);
            $this->assertTrue(touch($barrier));
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                if (preg_match('/RESULT:(.*)/', $worker->getOutput(), $match) !== 1) {
                    $this->fail($worker->getOutput());
                }
                $results[] = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([200, 200], $statuses);
            $this->assertDatabaseCount('statistical_observations', 1);
            $this->assertDatabaseCount('observation_revisions', 1);
            $this->assertDatabaseCount('statistical_import_receipts', 1);
            $this->assertDatabaseCount('statistical_import_executions', $sameExecution ? 1 : 2);
            $executions = StatisticalImportExecution::query()->limit(2)->get();
            $outcomes = $executions->map(fn ($execution) => $execution->results[2]['status'])->all();
            sort($outcomes);
            $this->assertSame($sameExecution ? ['created'] : ['created', 'duplicate'], $outcomes);
            foreach ($executions as $execution) {
                $this->assertSame('completed', $execution->status);
                $this->assertSame(1, $execution->processed_count);
                $snapshot = $execution->getRawOriginal();
                $this->assertFalse(app(StatisticalImportProcessor::class)->process($context, $execution->import_id, $execution->version));
                $this->assertSame($snapshot, $execution->refresh()->getRawOriginal());
            }
            $this->assertDatabaseCount('statistical_observations', 1);
            $this->assertDatabaseCount('observation_revisions', 1);
            $this->assertDatabaseCount('statistical_import_receipts', 1);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::setDefaultConnection($original);
            DB::purge('match001_race');
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }
    }
}
