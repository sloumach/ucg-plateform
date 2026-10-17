<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesCalendarInvitations;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CalendarInvitationConcurrencyTest extends TestCase
{
    use AuthenticatesWithMfa,ProvidesCalendarInvitations,ProvidesPeopleAccess;

    /** @return array<string,array{string}> */
    public static function operations(): array
    {
        return ['issue once' => ['issue'], 'deliver once' => ['deliver'], 'versioned response' => ['respond']];
    }

    #[DataProvider('operations')]
    public function test_independent_processes_serialize_issuance_delivery_and_response(string $operation): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real invitation concurrency is exercised by the PostgreSQL CI selection.');
        }
        $original = DB::getDefaultConnection();
        $config = config('database.connections.pgsql');
        $admin = DB::connection()->getPdo();
        $database = 'ucg_cal004_concurrency_'.bin2hex(random_bytes(6));
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ucg_cal004_go_'.bin2hex(random_bytes(12));
        $workers = [];
        try {
            $admin->exec('CREATE DATABASE "'.$database.'"');
            config(['database.connections.cal004_concurrency' => [...$config, 'url' => null, 'database' => $database]]);
            DB::setDefaultConnection('cal004_concurrency');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
            [$org, $editor, $recipient, , $activity] = $this->invitationScenario();
            $record = $operation === 'issue' ? null : $this->issueInvitation($org, $editor, $activity);
            if ($operation === 'respond') {
                $this->processInvitations($org);
                $record?->refresh();
            }
            $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            foreach (['present', 'absent'] as $response) {
                $payload = ['operation' => $operation, 'org' => $org->id, 'actor' => $operation === 'respond' ? $recipient->id : $editor->id,
                    'activity' => $activity->id, 'invitation' => $record?->id, 'now' => CarbonImmutable::now()->toIso8601String(),
                    'input' => $operation === 'respond' ? ['version' => $record?->version, 'response' => $response] : $this->invitationInput($activity)];
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/calendar-invitation-worker.php'), json_encode($payload, JSON_THROW_ON_ERROR), $barrier], base_path(), $environment, null, 60);
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
                    $this->fail('Both independent invitation workers must reach the barrier.');
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
            $this->assertSame($operation === 'deliver' ? [200, 200] : [200, 409], $statuses);
            $this->assertDatabaseCount('calendar_invitations', 1);
            $this->assertDatabaseCount('calendar_invitation_deliveries', $operation === 'issue' ? 0 : 1);
            $this->assertDatabaseCount('organization_audit_events', $operation === 'respond' ? 2 : 1);
            if ($operation !== 'deliver') {
                $conflict = array_values(array_filter($results, fn (array $r): bool => $r['status'] === 409));
                $this->assertSame($operation === 'issue' ? 'CALENDAR_INVITATIONS_ALREADY_ISSUED' : 'CALENDAR_INVITATION_STALE', $conflict[0]['code'] ?? null);
            }
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::setDefaultConnection($original);
            DB::purge('cal004_concurrency');
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }
    }
}
