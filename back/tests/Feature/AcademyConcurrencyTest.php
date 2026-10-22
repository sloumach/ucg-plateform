<?php

namespace Tests\Feature;

use App\Modules\Academy\Application\Services\AcademySessionService;
use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\Cohort;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class AcademyConcurrencyTest extends TestCase
{
    use ProvidesPeopleAccess;

    /** @return array<string, array{string}> */
    public static function races(): array
    {
        return ['same cohort version' => ['cohort'], 'duplicate enrollment' => ['enrollment'], 'same session version' => ['session'], 'duplicate presence' => ['attendance']];
    }

    #[DataProvider('races')]
    public function test_independent_processes_cannot_overwrite_or_duplicate(string $kind): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real academy concurrency runs on PostgreSQL.');
        }
        $original = DB::getDefaultConnection();
        $config = config('database.connections.pgsql');
        $admin = DB::connection()->getPdo();
        $database = 'ucg_aca001_race_'.bin2hex(random_bytes(6));
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ucg_aca001_go_'.bin2hex(random_bytes(12));
        $workers = [];
        try {
            $admin->exec('CREATE DATABASE "'.$database.'"');
            config(['database.connections.aca001_race' => [...$config, 'url' => null, 'database' => $database]]);
            DB::setDefaultConnection('aca001_race');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
            $org = Organization::factory()->create();
            $actor = User::factory()->withMfa()->create();
            $this->grantPeopleAccess($org, $actor, ['academy.read', 'academy.manage', 'academy.attendance.manage', 'calendar.read']);
            $cohort = Cohort::factory()->create(['organization_id' => $org->id]);
            $cycle = AcademyCycle::factory()->create(['cohort_id' => $cohort->id]);
            $person = Person::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
            $input = $kind === 'cohort' ? ['version' => $cohort->version, 'name' => 'Concurrent']
                : ['cycle_id' => $cycle->id, 'person_id' => $person->id, 'role' => 'student', 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31'];
            $payload = ['kind' => $kind, 'org' => $org->id, 'actor' => $actor->id, 'input' => $input,
                'cohort' => $kind === 'cohort' ? null : $cohort->id, 'id' => $kind === 'cohort' ? $cohort->id : null];
            if (in_array($kind, ['session', 'attendance'], true)) {
                $mentorUser = User::factory()->create();
                OrganizationMembership::factory()->create(['organization_id' => $org->id, 'user_id' => $mentorUser->id]);
                $mentorPerson = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $mentorUser->id]);
                $mentor = AcademyEnrollment::factory()->create(['cycle_id' => $cycle->id, 'person_id' => $mentorPerson->id, 'role' => 'mentor']);
                $student = AcademyEnrollment::factory()->create(['cycle_id' => $cycle->id, 'person_id' => $person->id]);
                $activity = CalendarActivity::factory()->shared()->create(['organization_id' => $org->id, 'kind' => 'academy', 'starts_at' => '2026-10-22 10:00:00', 'ends_at' => '2026-10-22 11:00:00']);
                $session = app(AcademySessionService::class)->save($this->personContext($org, $actor), $cohort->id, $cycle->id,
                    ['title' => 'Séance concurrente', 'responsible_enrollment_id' => $mentor->id, 'activity_id' => $activity->id, 'activity_version' => $activity->version]);
                $payload = ['kind' => $kind, 'org' => $org->id, 'actor' => $actor->id, 'cohort' => $cohort->id, 'cycle' => $cycle->id, 'session' => $session->id,
                    'id' => $kind === 'session' ? $session->id : null, 'input' => $kind === 'session'
                        ? ['version' => $session->version, 'activity_version' => $activity->version, 'title' => 'Correction concurrente', 'change_reason' => 'Correction de titre motivée.']
                        : ['enrollment_id' => $student->id, 'status' => 'present']];
            }
            $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            for ($index = 0; $index < 2; $index++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/academy-worker.php'), json_encode($payload, JSON_THROW_ON_ERROR), $barrier], base_path(), $environment, null, 60);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 20;
            while (true) {
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
                    $this->fail('Both independent academy workers must reach the barrier.');
                }
                usleep(10000);
            }
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
            $conflict = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 409));
            if (in_array($kind, ['session', 'attendance'], true)) {
                $this->assertSame($kind === 'session' ? 'ACADEMY_SESSION_VERSION_STALE' : 'ACADEMY_ATTENDANCE_ALREADY_EXISTS', $conflict[0]['code']);
                $this->assertDatabaseCount('academy_sessions', 1);
                $this->assertDatabaseCount('academy_attendances', $kind === 'attendance' ? 1 : 0);
                $this->assertDatabaseCount('academy_session_revisions', 2);
                $this->assertDatabaseCount('organization_audit_events', 2);

                return;
            }
            $this->assertSame($kind === 'cohort' ? 'ACADEMY_VERSION_STALE' : 'ACADEMY_ENROLLMENT_OVERLAP', $conflict[0]['code']);
            $this->assertDatabaseCount('academy_enrollments', $kind === 'cohort' ? 0 : 1);
            $this->assertDatabaseCount('academy_revisions', 1);
            $this->assertDatabaseCount('organization_audit_events', 1);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::setDefaultConnection($original);
            DB::purge('aca001_race');
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }
    }
}
