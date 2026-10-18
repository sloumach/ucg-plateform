<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchResults;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchResultConcurrencyTest extends TestCase
{
    use AuthenticatesWithMfa, ProvidesMatchResults, ProvidesPeopleAccess;

    /** @return array<string,array{string}> */
    public static function races(): array
    {
        return ['two submissions' => ['submit'], 'two approvals' => ['approve'], 'approval versus dispute' => ['dispute'], 'score versus submission' => ['score']];
    }

    #[DataProvider('races')]
    public function test_independent_processes_cannot_overwrite_one_version(string $operation): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real match concurrency runs in the PostgreSQL CI selection.');
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
            $scenario = $this->resultScenario($operation !== 'score');
            $org = $scenario['org'];
            $writer = $scenario['writer'];
            $data = $scenario['record'];
            $result = null;
            if (in_array($operation, ['approve', 'dispute'], true)) {
                $result = $this->postJson($scenario['root'].'/results', $this->resultPayload($scenario))->assertCreated()->json('data');
                $data['version'] = $result['match_version'];
                $writer = $scenario['reader'];
            }
            $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            for ($i = 0; $i < 2; $i++) {
                $action = in_array($operation, ['approve', 'dispute'], true) ? ($operation === 'dispute' && $i === 1 ? 'dispute' : 'approve') : 'submit';
                $body = $result === null ? $this->resultPayload($scenario, $operation === 'score' ? 'forfeit' : 'played') : $this->decisionPayload($result);
                if ($action === 'dispute') {
                    unset($body['confirm_evidence']);
                }
                $payload = ['operation' => 'result_'.$action, 'org' => $org->id, 'actor' => $writer->id, 'match' => $data['id'],
                    'now' => CarbonImmutable::now()->toIso8601String(), 'input' => $body];
                if ($operation === 'score' && $i === 1) {
                    $payload['operation'] = 'game';
                    $payload['input'] = $this->scorePayload($scenario);
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
                    $this->fail('Both independent match workers must reach the barrier.');
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
            $this->assertSame([200, 409], $statuses);
            $conflict = array_values(array_filter($results, fn (array $r): bool => $r['status'] === 409));
            $this->assertSame('MATCH_VERSION_STALE', $conflict[0]['code']);
            $this->assertDatabaseCount('matches', 1);
            $this->assertDatabaseCount('match_participants', 2);
            $this->assertDatabaseCount('organization_audit_events', $operation === 'score' ? 5 : ($result === null ? 6 : 7));
            $count = DB::table('match_result_events')->count();
            if ($operation === 'score') {
                $this->assertContains($count, [0, 1]);
                $this->assertDatabaseCount('match_game_instances', $count === 0 ? 1 : 0);
            } else {
                $this->assertSame($result === null ? 1 : 2, $count);
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
