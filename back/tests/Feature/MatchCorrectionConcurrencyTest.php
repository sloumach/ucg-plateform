<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchCorrections;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchCorrectionConcurrencyTest extends TestCase
{
    use AuthenticatesWithMfa, ProvidesMatchCorrections, ProvidesPeopleAccess;

    /** @return array<string,array{string}> */
    public static function races(): array
    {
        return ['different requests' => ['request'], 'duplicate request' => ['duplicate'], 'duplicate jobs' => ['apply']];
    }

    #[DataProvider('races')]
    public function test_concurrent_requests_and_jobs_cannot_duplicate_scores_or_audit(string $operation): void
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
            $s = $this->correctionScenario();
            $org = $s['org'];
            $writer = $s['writer'];
            $data = $s['record'];
            $body = $this->correctionPayload($s);
            $correction = $operation === 'apply' ? $this->postJson($s['root'].'/corrections', $body)->assertStatus(202)->json('data') : null;
            $audits = DB::table('organization_audit_events')->count();
            $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array'];
            for ($i = 0; $i < 2; $i++) {
                $input = $body;
                if ($operation === 'request' && $i === 1) {
                    $input['idempotency_key'] = (string) Str::uuid();
                }
                $payload = ['operation' => $operation === 'apply' ? 'correction_apply' : 'correction', 'org' => $org->id, 'actor' => $writer->id, 'match' => $data['id'],
                    'correction' => $correction['id'] ?? null, 'now' => CarbonImmutable::now()->toIso8601String(), 'input' => $input];
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
            $this->assertSame($operation === 'request' ? [200, 409] : [200, 200], $statuses);
            if ($operation === 'request') {
                $conflict = array_values(array_filter($results, fn ($r) => $r['status'] === 409));
                $this->assertContains($conflict[0]['code'], ['MATCH_RECALCULATION_PENDING', 'MATCH_VERSION_STALE']);
            }
            $this->assertDatabaseCount('match_score_corrections', 1);
            $this->assertDatabaseCount('match_score_recalculations', $operation === 'apply' ? 1 : 0);
            $this->assertDatabaseCount('match_game_instances', 1);
            $this->assertDatabaseCount('organization_audit_events', $audits + ($operation === 'apply' ? 1 : 2));
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
