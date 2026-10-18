<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesExternalCompetitions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ExternalCompetitionConcurrencyTest extends TestCase
{
    use AuthenticatesWithMfa, ProvidesExternalCompetitions, ProvidesPeopleAccess;

    /** @return array<string,array{string}> */
    public static function operations(): array
    {
        return ['record' => ['record'], 'document' => ['document'], 'archive/document' => ['archive_document']];
    }

    #[DataProvider('operations')]
    public function test_independent_processes_serialize_versions_and_archiving(string $operation): void
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
            $s = $this->competitionScenario();
            $document = $operation === 'record' ? null : $this->postJson($s['root'].'/'.$s['record']['id'].'/documents', $this->competitionDocument($s))->assertCreated()->json('data');
            $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            for ($i = 0; $i < 2; $i++) {
                $isDocument = $operation === 'document' || ($operation === 'archive_document' && $i === 1);
                $input = $isDocument ? ['competition_version' => $s['record']['version'], 'version' => $document['version'], 'body' => 'Texte révisé '.$i, 'reason' => 'Révision indépendante du règlement privé.']
                    : ['version' => $s['record']['version'], ...($operation === 'archive_document' ? ['archive' => true] : ['name' => 'Nom révisé '.$i]), 'reason' => 'Modification du dossier externe par le manager.'];
                $payload = ['operation' => $isDocument ? 'external_competition_document' : 'external_competition', 'org' => $s['org']->id, 'actor' => $s['writer']->id, 'competition' => $s['record']['id'],
                    'document' => $document['document_id'] ?? null, 'now' => CarbonImmutable::now()->toIso8601String(), 'input' => $input];
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
            if ($operation === 'archive_document') {
                $this->assertContains($statuses, [[200, 200], [200, 409]]);
                $this->assertDatabaseHas('external_competitions', ['id' => $s['record']['id'], 'status' => 'archived']);
                $this->assertDatabaseCount('external_competition_revisions', 2);
            } else {
                $this->assertSame([200, 409], $statuses);
                $this->assertDatabaseCount($operation === 'record' ? 'external_competition_revisions' : 'external_competition_documents', 2);
                $conflict = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 409));
                $this->assertSame($operation === 'record' ? 'EXTERNAL_COMPETITION_VERSION_STALE' : 'EXTERNAL_COMPETITION_DOCUMENT_STALE', $conflict[0]['code']);
            }
            $this->assertDatabaseCount('external_competitions', 1);
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
