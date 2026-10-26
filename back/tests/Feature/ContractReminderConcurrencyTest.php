<?php

namespace Tests\Feature;

use App\Modules\Contracts\Application\Services\ContractReminderService;
use App\Modules\Contracts\Application\Services\ContractService;
use App\Modules\Contracts\Application\Services\ProcessContractReminders;
use App\Modules\Contracts\Domain\Contracts\ContractReminderMailer;
use App\Modules\Contracts\Domain\Models\ContractReminderDelivery;
use App\Modules\Contracts\Domain\Models\ContractReminderPlan;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\FakeContractReminderMailer;
use Tests\Fixtures\ProvidesContractDocuments;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ContractReminderConcurrencyTest extends TestCase
{
    use AuthenticatesWithMfa, ProvidesContractDocuments, ProvidesPeopleAccess;

    /** @return array<string, array{bool, bool}> */
    public static function runs(): array
    {
        return ['two runs for Alpha' => [false, false], 'Alpha and Beta' => [true, false],
            'ambiguous acknowledgement for Alpha' => [false, true]];
    }

    #[DataProvider('runs')]
    public function test_concurrent_runs_and_replays_keep_one_attempt_per_tenant_occurrence(bool $differentTenants, bool $ambiguous): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real reminder concurrency requires PostgreSQL.');
        }
        $original = DB::getDefaultConnection();
        $config = config('database.connections.pgsql');
        $admin = DB::connection()->getPdo();
        $database = 'ucg_match001_race_'.bin2hex(random_bytes(6));
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ucg_match001_go_'.bin2hex(random_bytes(12));
        $workers = [];
        try {
            $admin->exec('CREATE DATABASE "'.$database.'"');
            config(['database.connections.reminder_race' => [...$config, 'url' => null, 'database' => $database]]);
            DB::setDefaultConnection('reminder_race');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
            $this->travelTo(CarbonImmutable::parse('2026-10-26T10:00:00Z'));
            $alpha = $this->reminderScenario();
            $beta = $this->reminderScenario();
            $betaContract = (array) DB::table('contracts')->where('organization_id', $beta->id)->first();
            $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            foreach ([$alpha, $differentTenants ? $beta : $alpha] as $org) {
                $payload = ['operation' => 'contract_reminders', 'org' => $org->id, 'actor' => $org->owner_user_id,
                    'ambiguous' => $ambiguous, 'now' => CarbonImmutable::now()->toIso8601String()];
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/match-worker.php'), json_encode($payload, JSON_THROW_ON_ERROR), $barrier], base_path(), $environment, null, 60);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 25;
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
                    $this->fail('Both independent reminder workers must reach the barrier.');
                }
                usleep(10000);
            } while (true);
            $this->assertTrue(touch($barrier));
            $sent = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                if (preg_match('/RESULT:(.*)/', $worker->getOutput(), $match) !== 1) {
                    $this->fail($worker->getOutput());
                }
                $result = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(200, $result['status']);
                $sent = [...$sent, ...$result['sent_keys']];
            }
            $this->assertDatabaseCount('contract_reminder_deliveries', $differentTenants ? 2 : 1);
            $this->assertSame(1, ContractReminderDelivery::query()->where('organization_id', $alpha->id)->count());
            $this->assertSame($differentTenants ? 1 : 0, ContractReminderDelivery::query()->where('organization_id', $beta->id)->count());
            $records = ContractReminderDelivery::query()->orderBy('id')->limit(2)->get();
            $expectedKeys = $records->pluck('occurrence')->all();
            sort($sent);
            sort($expectedKeys);
            $this->assertSame($expectedKeys, $sent);
            foreach ($records as $delivery) {
                $this->assertSame($ambiguous ? 'uncertain' : 'sent', $delivery->email_status);
                $this->assertSame('sent', $delivery->in_app_status);
                $this->assertSame(1, $delivery->attempts);
            }
            $snapshot = $records->map(fn ($record) => $record->getRawOriginal())->all();
            $mailer = new FakeContractReminderMailer;
            app()->instance(ContractReminderMailer::class, $mailer);
            $this->travel(16)->minutes();
            foreach ($differentTenants ? [$alpha, $beta] : [$alpha] as $org) {
                app(ProcessContractReminders::class)->run(new TenantExecutionContext($org->id, (string) Str::uuid(), 'UTC', 'fr'), (string) Str::uuid());
            }
            $this->assertSame([], $mailer->sent);
            $this->assertSame($snapshot, ContractReminderDelivery::query()->orderBy('id')->limit(2)->get()->map(fn ($record) => $record->getRawOriginal())->all());
            $this->assertSame($betaContract, (array) DB::table('contracts')->where('organization_id', $beta->id)->first());
            if (! $differentTenants) {
                $this->assertNull(ContractReminderPlan::query()->where('organization_id', $beta->id)->value('checked_at'));
            }
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::setDefaultConnection($original);
            DB::purge('reminder_race');
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }
    }

    private function reminderScenario(): Organization
    {
        [$org, $user, $contract, $context] = $this->documentScenario();
        $contract->forceFill(['ends_on' => now()->addDays(30)->toDateString()])->save();
        Person::query()->whereKey($contract->responsible_person_id)->firstOrFail()->forceFill(['user_id' => $user->id, 'status' => 'active'])->save();
        app(ContractReminderService::class)->save($context, $contract->id, [
            'contract_version' => $contract->version, 'expected_version' => null, 'enabled' => true,
            'notice_on' => null, 'renewal_on' => null, 'offsets' => [30], 'channels' => ['in_app', 'email']]);
        $contract->refresh();
        app(ContractService::class)->transition($context, $contract->id, ['version' => $contract->version, 'status' => 'in_review']);
        $reviewer = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $reviewer, ['administrative.read', 'administrative.write', 'administrative.validate']);
        $contract->refresh();
        app(ContractService::class)->transition($this->personContext($org, $reviewer), $contract->id, ['version' => $contract->version, 'status' => 'approved']);

        return $org;
    }
}
