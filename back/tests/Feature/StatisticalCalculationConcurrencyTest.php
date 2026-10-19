<?php

namespace Tests\Feature;

use App\Modules\Performance\Application\Services\StatisticalCalculationProcessor;
use App\Modules\Performance\Application\Services\StatisticalCalculationService;
use App\Modules\Performance\Application\Validation\MetricCatalogueInput;
use App\Modules\Performance\Infrastructure\Queue\CalculateStatistics;
use App\Modules\Performance\Infrastructure\Queue\RecalculateObservationStatistics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMetricCatalogue;
use Tests\Fixtures\ProvidesObservations;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class StatisticalCalculationConcurrencyTest extends TestCase
{
    use AuthenticatesWithMfa, ProvidesMetricCatalogue, ProvidesObservations, ProvidesPeopleAccess;

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_concurrent_jobs_or_corrections_never_publish_an_obsolete_result(bool $corrects): void
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
            config(['statistical_calculations.processing_enabled' => true]);
            Queue::fake([CalculateStatistics::class, RecalculateObservationStatistics::class]);
            $s = $this->observationScenario();
            $this->actingAsWithMfa($s['operator']);
            $meta = $this->getJson('/api/v1/metric-catalogue')->assertOk()->json('meta');
            $metric = $this->patchJson('/api/v1/metric-catalogue/'.$s['metric']['id'], array_intersect_key([
                ...$s['metric'], 'aggregation' => 'sum', 'catalogue_version' => $meta['catalogue_version'],
                'game_catalogue_version' => $meta['game_catalogue_version'], 'confirm_global' => true,
                'reason' => 'Définition additive pour vérifier la concurrence.'], MetricCatalogueInput::rules('update')))->assertOk()->json('data');
            $this->actingAsWithMfa($s['writer']);
            $s['input']['metric_version'] = $metric['version'];
            $s['record'] = $this->postJson($s['root'], $s['input'])->assertCreated()->json('data');
            $context = $this->personContext($s['org'], $s['writer']);
            $calculation = app(StatisticalCalculationService::class)->create($context,
                ['metric_id' => $metric['id'], 'metric_version' => $metric['version'], 'observations' => [$s['record']['id']], 'confirm' => true]);
            $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array', 'STATISTICAL_CALCULATIONS_ENABLED' => 'true'];
            for ($i = 0; $i < 2; $i++) {
                $payload = ['operation' => 'statistical_calculation', 'org' => $s['org']->id, 'actor' => $s['writer']->id,
                    'calculation' => $calculation->id, 'version' => $calculation->version, 'now' => CarbonImmutable::now()->toIso8601String()];
                if ($corrects && $i === 1) {
                    $payload = ['operation' => 'observation', 'org' => $s['org']->id, 'actor' => $s['writer']->id,
                        'observation' => $s['record']['id'], 'now' => CarbonImmutable::now()->toIso8601String(),
                        'input' => [...$this->observationCorrection($s), 'value' => '10']];
                }
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
            $current = app(StatisticalCalculationService::class)->find($context, $calculation->id);
            if ($corrects) {
                $this->assertSame('stale', $current->status);
                $this->assertNull(app(StatisticalCalculationService::class)->project($current)['result']);
                app(StatisticalCalculationProcessor::class)->process($context, $current->id, $current->version);
                $current = app(StatisticalCalculationService::class)->find($context, $current->id);
                $this->assertSame('10', $current->current?->result['value']);
                $this->assertDatabaseCount('observation_revisions', 2);
            } else {
                $this->assertSame('0', $current->current?->result['value']);
                $this->assertDatabaseCount('statistical_calculation_revisions', 1);
                $this->assertDatabaseCount('observation_revisions', 1);
            }
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
