<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Services\ProcessCompetitionRegistrationReminders;
use App\Modules\Contracts\Application\Services\ContractReminderService;
use App\Modules\Contracts\Application\Services\ContractService;
use App\Modules\Contracts\Application\Services\ProcessContractReminders;
use App\Modules\Contracts\Domain\Models\ContractReminderPlan;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Infrastructure\Queue\RunTenantScheduledTask;
use App\Support\Operations\BackgroundSettings;
use App\Support\Operations\ReminderCoverageRepository;
use App\Support\Operations\RuntimeCheck;
use App\Support\Queue\JobContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesCompetitionRegistrations;
use Tests\Fixtures\ProvidesContractDocuments;
use Tests\Fixtures\ProvidesExternalCompetitions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ReminderCoverageTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesCompetitionRegistrations, ProvidesContractDocuments, ProvidesExternalCompetitions, ProvidesPeopleAccess;

    public function test_unchecked_contract_plan_is_detected_then_real_scheduled_task_materializes_reminder(): void
    {
        $this->configureCoverage();
        [$org, $user, $contract, $context] = $this->documentScenario();
        $contract->forceFill(['ends_on' => now()->addDays(5)->toDateString()])->save();
        Person::query()->whereKey($contract->responsible_person_id)->firstOrFail()->forceFill(['user_id' => $user->id, 'status' => 'active'])->save();
        app(ContractReminderService::class)->save($context, $contract->id, [
            'contract_version' => $contract->version, 'expected_version' => null, 'enabled' => true,
            'notice_on' => null, 'renewal_on' => null, 'offsets' => [7], 'channels' => ['in_app'],
        ]);
        $contract->refresh();
        app(ContractService::class)->transition($context, $contract->id, ['version' => $contract->version, 'status' => 'in_review']);
        $reviewer = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $reviewer, ['administrative.read', 'administrative.write', 'administrative.validate']);
        $contract->refresh();
        app(ContractService::class)->transition($this->personContext($org, $reviewer), $contract->id, ['version' => $contract->version, 'status' => 'approved']);
        $this->travel(1800)->seconds();
        $before = DB::table('contract_reminder_plans')->get()->toJson();

        $check = $this->coverage('contract_sweeps');

        $this->assertSame('REMINDER_SWEEP_REVIEW', $check->code);
        $this->assertSame(1, $check->observations['never_checked_observed']);
        $this->assertSame(1, $check->observations['late_observed']);
        $this->assertSame($before, DB::table('contract_reminder_plans')->get()->toJson());
        $this->assertDatabaseCount('contract_reminder_deliveries', 0);
        $id = (string) Str::uuid();
        RunTenantScheduledTask::dispatchSync(new JobContext($id, $org->id, null), 'contract-reminders', $id);
        $this->assertDatabaseCount('contract_reminder_deliveries', 1);
        $this->assertSame('REMINDER_SWEEP_METADATA_PASSED', $this->coverage('contract_sweeps')->code);
        $this->assertDatabaseHas('contract_reminder_deliveries', ['in_app_status' => 'sent', 'email_status' => 'not_requested']);
    }

    public function test_competition_sweep_tracks_latest_revision_then_real_task_creates_reminder(): void
    {
        $this->configureCoverage();
        $s = $this->registrationScenario(true);
        $this->travel(1800)->seconds();

        $check = $this->coverage('competition_sweeps');

        $this->assertSame('REMINDER_SWEEP_REVIEW', $check->code);
        $this->assertSame(1, $check->observations['late_observed']);
        $this->assertFalse($check->observations['materialization_and_delivery_verified']);
        $this->assertDatabaseCount('competition_registration_reminders', 0);
        $id = (string) Str::uuid();
        RunTenantScheduledTask::dispatchSync(new JobContext($id, $s['org']->id, null), 'competition-registration-reminders', $id);
        $this->assertDatabaseCount('competition_registration_reminders', 1);
        $this->assertSame('REMINDER_SWEEP_METADATA_PASSED', $this->coverage('competition_sweeps')->code);
        $input = $s['input'];
        $input['expected_version'] = $s['revision']['version'];
        $input['items'][0]['status'] = 'done';
        $this->patchJson($s['path'], $input)->assertOk();
        $this->travel(1800)->seconds();
        $this->assertSame(0, $this->coverage('competition_sweeps')->observations['sampled']);
    }

    public function test_ineligible_contract_plans_are_not_treated_as_due_reminders(): void
    {
        $this->configureCoverage();
        [$org, $user, $contract] = $this->documentScenario();
        (new ContractReminderPlan)->forceFill(['organization_id' => $org->id, 'contract_id' => $contract->id,
            'enabled' => true, 'offsets' => [7], 'channels' => ['in_app'],
            'version' => (string) Str::uuid(), 'updated_by' => $user->id])->save();
        $this->travel(3600)->seconds();

        $this->assertSame(0, $this->coverage('contract_sweeps')->observations['sampled']);
    }

    public function test_competition_metadata_sample_is_bounded_and_omits_payload_and_identities(): void
    {
        $this->configureCoverage();
        $s = $this->registrationScenario(true);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $check = $this->coverage('competition_sweeps');

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('limit 1001', $queries[0]);
        $this->assertStringNotContainsString('payload', $queries[0]);
        $this->assertStringNotContainsString('reason', $queries[0]);
        $json = json_encode($check->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($s['org']->id, $json);
        $this->assertStringNotContainsString('Pièce', $json);
    }

    public function test_partial_sweep_sample_is_never_a_complete_success(): void
    {
        $this->configureCoverage();
        $this->registrationScenario(true);
        $this->registrationScenario(true);
        config(['operations.background.sample_limit' => 1]);

        $check = $this->coverage('competition_sweeps');

        $this->assertSame('REMINDER_SWEEP_TRUNCATED', $check->code);
        $this->assertTrue($check->observations['truncated']);
        $this->assertSame(1, $check->observations['sampled']);
    }

    private function configureCoverage(): void
    {
        $this->freezeTime();
        config(['operations.resource_namespace' => 'ops_coverage', 'operations.background.cache_store' => 'database',
            'queue.default' => 'database', 'tenancy.cache_store' => 'database', 'tenancy.schedule_connection' => 'database',
            'tenancy.scheduled_tasks' => ['contract-reminders' => ProcessContractReminders::class,
                'competition-registration-reminders' => ProcessCompetitionRegistrationReminders::class]]);
    }

    private function coverage(string $source): RuntimeCheck
    {
        return app(ReminderCoverageRepository::class)->inspect($source, BackgroundSettings::from(config()));
    }
}
