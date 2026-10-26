<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarResource;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CalendarConflictConcurrencyTest extends TestCase
{
    use ProvidesPeopleAccess;

    /** @return array<string,array{string,bool}> */
    public static function collisions(): array
    {
        return ['person' => ['person', false], 'team' => ['team', false], 'resource' => ['resource', false],
            'resources in distinct tenants' => ['resource', true]];
    }

    #[DataProvider('collisions')]
    public function test_two_previews_then_concurrent_saves_allow_only_one_booking(string $kind, bool $differentTenants): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real concurrency requires PostgreSQL; exercised in the PostgreSQL CI selection.');
        }
        $original = DB::getDefaultConnection();
        $config = config('database.connections.pgsql');
        $admin = DB::connection()->getPdo();
        $database = 'ucg_cal003_concurrency_'.bin2hex(random_bytes(6));
        $workers = [];
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ucg_cal003_go_'.bin2hex(random_bytes(12));
        try {
            $admin->exec('CREATE DATABASE "'.$database.'"');
            config(['database.connections.cal003_concurrency' => [...$config, 'url' => null, 'database' => $database]]);
            DB::setDefaultConnection('cal003_concurrency');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
            $this->freezeTime();
            $org = Organization::factory()->create();
            $other = $differentTenants ? Organization::factory()->create() : $org;
            $actors = [User::factory()->withMfa()->create(), User::factory()->withMfa()->create()];
            foreach ($actors as $index => $actor) {
                $this->grantPeopleAccess($index === 0 ? $org : $other, $actor, ['calendar.read', 'calendar.write']);
            }
            $input = ['title' => 'Réservation concurrente', 'kind' => 'training', 'starts_at' => '2026-10-18T10:00:00+02:00', 'ends_at' => '2026-10-18T11:00:00+02:00', 'origin_timezone' => 'Europe/Paris'];
            $input += match ($kind) {
                'person' => ['participant_ids' => [Person::factory()->create(['organization_id' => $org->id])->id]],
                'team' => ['team_id' => Team::factory()->create(['organization_id' => $org->id])->id],
                'resource' => ['resource_ids' => [CalendarResource::factory()->create(['organization_id' => $org->id])->id]],
                default => throw new \InvalidArgumentException('Unknown collision kind.'),
            };
            $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            foreach ($actors as $index => $actor) {
                $target = $index === 0 ? $org : $other;
                $targetInput = $differentTenants && $index === 1
                    ? [...$input, 'resource_ids' => [CalendarResource::factory()->create(['organization_id' => $other->id])->id]] : $input;
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/calendar-conflict-worker.php'),
                    json_encode(['org' => $target->id, 'actor' => $actor->id, 'input' => $targetInput], JSON_THROW_ON_ERROR), $barrier], base_path(), $environment, null, 60);
                $process->start();
                $workers[] = $process;
            }
            $deadline = microtime(true) + 15;
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
                    $this->fail('Both independent workers must review before the write barrier opens.');
                }
                usleep(10000);
            } while (true);
            // Both transactions reviewed the empty slot before either is released to write.
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
            $this->assertSame($differentTenants ? [201, 201] : [201, 409], $statuses);
            if ($differentTenants) {
                $this->assertSame(1, CalendarActivity::query()->where('organization_id', $other->id)->count());
            } else {
                $conflict = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 409));
                $this->assertSame('CALENDAR_CONFLICTS_CHANGED', $conflict[0]['code'] ?? null);
            }
            $this->assertSame(1, CalendarActivity::query()->where('organization_id', $org->id)->count());
            $this->assertDatabaseCount('calendar_activities', $differentTenants ? 2 : 1);
            $this->assertDatabaseCount('calendar_conflict_overrides', 0);
            $this->assertDatabaseCount('organization_audit_events', $differentTenants ? 2 : 1);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::setDefaultConnection($original);
            DB::purge('cal003_concurrency');
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }
    }
}
