<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Services\MatchScoreService;
use App\Modules\Competition\Domain\Contracts\MatchRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchScores;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchScoreConcurrencyTest extends TestCase
{
    use AuthenticatesWithMfa, ProvidesMatchScores, ProvidesPeopleAccess;

    public function test_coherent_history_readers_share_the_match_but_exclude_writers_until_both_finish(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real history read locks run in the PostgreSQL CI selection.');
        }
        $original = DB::getDefaultConnection();
        $config = config('database.connections.pgsql');
        $admin = DB::connection()->getPdo();
        $database = 'ucg_match_read_'.bin2hex(random_bytes(6));
        $connections = ['match_read_primary', 'match_read_peer', 'match_read_writer'];
        $created = false;
        try {
            $admin->exec('CREATE DATABASE "'.$database.'"');
            $created = true;
            foreach ($connections as $name) {
                config(['database.connections.'.$name => [...$config, 'url' => null, 'database' => $database]]);
            }
            DB::setDefaultConnection($connections[0]);
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
            $scenario = $this->scoreScenario();
            $organization = $scenario['org'];
            $actor = $scenario['writer'];
            $match = $scenario['record'];
            $viewer = ['person_id' => null, 'team_ids' => []];
            $primary = DB::connection($connections[0]);
            $peer = DB::connection($connections[1]);
            $writer = DB::connection($connections[2]);
            $primary->beginTransaction();
            $record = app(MatchRepository::class)->findForSharedRead($organization->id, $match['id'], $actor->id, $viewer, false);
            $this->assertSame($match['version'], $record->version);

            DB::setDefaultConnection($connections[1]);
            $peer->beginTransaction();
            $peer->statement("SET LOCAL lock_timeout = '500ms'");
            $history = app(MatchScoreService::class)->index($this->personContext($organization, $actor), $match['id'], ['per_page' => 25]);
            $this->assertSame($match['version'], $history['match_version']);
            $this->assertSame(0, $history['games']->total());

            DB::setDefaultConnection($connections[2]);
            $assertWriterBlocked = function () use ($writer, $organization, $match, $actor, $viewer): void {
                $writer->beginTransaction();
                $writer->statement("SET LOCAL lock_timeout = '500ms'");
                try {
                    app(MatchRepository::class)->find($organization->id, $match['id'], $actor->id, $viewer, false, true);
                    $this->fail('A score writer must wait for coherent history readers.');
                } catch (QueryException $exception) {
                    $this->assertSame('55P03', $exception->errorInfo[0] ?? null);
                } finally {
                    $writer->rollBack();
                }
            };
            $assertWriterBlocked();
            $primary->rollBack();
            $assertWriterBlocked();
            $peer->rollBack();
            $writer->beginTransaction();
            $writer->statement("SET LOCAL lock_timeout = '500ms'");
            $this->assertSame($match['version'], app(MatchRepository::class)->find($organization->id, $match['id'], $actor->id, $viewer, false, true)->version);
            $writer->rollBack();
            $this->assertDatabaseCount('matches', 1);
            $this->assertDatabaseCount('match_game_instances', 0);
        } finally {
            DB::setDefaultConnection($original);
            foreach ($connections as $name) {
                $connection = DB::getConnections()[$name] ?? null;
                if ($connection !== null) {
                    while ($connection->transactionLevel() > 0) {
                        $connection->rollBack();
                    }
                }
                DB::purge($name);
            }
            if ($created) {
                $admin->exec('DROP DATABASE "'.$database.'" WITH (FORCE)');
            }
        }
    }

    /** @return array<string,array{string}> */
    public static function races(): array
    {
        return ['two rules' => ['rules'], 'two game writes' => ['game'], 'game versus cancellation' => ['cancel']];
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
            $scenario = $this->scoreScenario(configure: $operation !== 'rules');
            $org = $scenario['org'];
            $writer = $scenario['writer'];
            $data = $scenario['record'];
            $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            for ($i = 0; $i < 2; $i++) {
                $payload = ['operation' => $operation === 'rules' ? 'score_rules' : ($operation === 'cancel' && $i === 1 ? 'transition' : 'game'),
                    'org' => $org->id, 'actor' => $writer->id, 'match' => $data['id'], 'now' => CarbonImmutable::now()->toIso8601String(),
                    'input' => $operation === 'rules' ? ['version' => $data['version'], 'game_limit' => 3, 'allow_draw' => false, 'reason' => 'Règles concurrentes explicitement confirmées.'] : $this->scorePayload($scenario)];
                if ($operation === 'cancel' && $i === 1) {
                    $payload['input'] = ['version' => $data['version'], 'status' => 'cancelled', 'reason' => 'Annulation concurrente explicitement motivée.'];
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
            $this->assertDatabaseCount('organization_audit_events', $operation === 'rules' ? 4 : 5);
            $status = DB::table('matches')->value('status');
            $this->assertContains($status, ['in_progress', 'cancelled']);
            $this->assertDatabaseCount('match_score_rules', 1);
            $this->assertDatabaseCount('match_game_instances', $operation === 'rules' || $status === 'cancelled' ? 0 : 1);
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
