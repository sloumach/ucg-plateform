<?php

namespace Tests\Feature;

use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatches;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchNotebookConcurrencyTest extends TestCase
{
    use AuthenticatesWithMfa, ProvidesMatches, ProvidesPeopleAccess;

    public function test_independent_processes_cannot_overwrite_one_notebook_version(): void
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
            [$org,$writer,,$person,$other,$game] = $this->matchScenario();
            $data = $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated()->json('data');
            $roleId = OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $writer->id)->value('role_id');
            OrganizationRole::query()->where('id', $roleId)->firstOrFail()->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.notes.read', 'sports.notes.write']])->save();
            $entry = $this->postJson($this->matchRoot($org).'/'.$data['id'].'/notebook', ['kind' => 'note', 'audience' => 'internal', 'title' => 'Analyse du match', 'body' => 'Note initiale conservée.'])->assertCreated()->json('data');
            $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            for ($i = 0; $i < 2; $i++) {
                $payload = ['operation' => 'notebook', 'org' => $org->id, 'actor' => $writer->id, 'match' => $data['id'], 'entry' => $entry['entry_id'],
                    'now' => CarbonImmutable::now()->toIso8601String(), 'input' => ['version' => $entry['version'], 'body' => 'Révision concurrente '.$i]];
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
            $this->assertSame('MATCH_NOTEBOOK_VERSION_STALE', $conflict[0]['code']);
            $this->assertDatabaseCount('matches', 1);
            $this->assertDatabaseCount('match_participants', 2);
            $this->assertDatabaseCount('organization_audit_events', 3);
            $this->assertDatabaseCount('match_notebook_revisions', 2);
            $this->assertDatabaseHas('matches', ['id' => $data['id'], 'version' => $data['version']]);
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
