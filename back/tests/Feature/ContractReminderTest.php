<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Contracts\Application\Services\ContractFinancialService;
use App\Modules\Contracts\Application\Services\ContractReminderService;
use App\Modules\Contracts\Application\Services\ContractService;
use App\Modules\Contracts\Application\Services\ProcessContractReminders;
use App\Modules\Contracts\Domain\ContractReminderSchedule;
use App\Modules\Contracts\Domain\Contracts\ContractReminderMailer;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Domain\Models\ContractReminderDelivery;
use App\Modules\Contracts\Domain\Models\ContractReminderPlan;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Infrastructure\Queue\RunTenantScheduledTask;
use App\Support\Queue\JobContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\FakeContractReminderMailer;
use Tests\Fixtures\ProvidesContractDocuments;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ContractReminderTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesContractDocuments, ProvidesPeopleAccess;

    private FakeContractReminderMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T10:00:00Z'));
        config(['tenancy.cache_store' => 'database', 'tenancy.schedule_connection' => 'database',
            'tenancy.scheduled_tasks' => ['contract-reminders' => ProcessContractReminders::class]]);
        $this->mailer = new FakeContractReminderMailer;
        app()->instance(ContractReminderMailer::class, $this->mailer);
    }

    public function test_settings_are_versioned_authorized_and_in_portability_inventory(): void
    {
        [$org, $user, $contract, $context] = $this->documentScenario();
        $this->actingAsWithMfa($user);
        $path = $this->path($org, $contract);
        $this->getJson($path)->assertOk()->assertJsonPath('data', null)->assertHeader('Cache-Control', 'no-store, private');
        $version = $contract->version;
        $response = $this->patchJson($path, $this->settings($contract))->assertOk()->assertJsonPath('data.offsets', [7, 30, 90])
            ->assertJsonPath('data.channels', ['in_app', 'email'])->assertJsonPath('notification.type', 'success');
        $contract->refresh();
        $this->assertNotSame($version, $contract->version);
        $this->patchJson($path, [...$this->settings($contract), 'contract_version' => $version])->assertConflict();
        $this->patchJson($path, $this->settings($contract))->assertConflict();
        $this->patchJson($path, [...$this->settings($contract), 'expected_version' => $response->json('data.version'), 'enabled' => false])->assertOk();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
        $this->assertDatabaseCount('contract_reminder_plans', 1);
        $this->assertStringContainsString('configured', json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR));
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function invalidSettings(): array
    {
        return ['numeric string' => [['offsets' => [30, '30']], 'offsets.1'], 'float' => [['offsets' => [30.5]], 'offsets.0'], 'duplicates' => [['offsets' => [30, 30]], 'offsets.0'], 'negative' => [['offsets' => [-1]], 'offsets.0'],
            'too many' => [['offsets' => range(1, 11)], 'offsets'], 'empty channels' => [['channels' => []], 'channels'],
            'foreign channel' => [['channels' => ['sms']], 'channels.0'], 'invalid date' => [['notice_on' => '2026-02-30'], 'notice_on'],
            'unknown' => [['email' => 'external@example.com'], 'email']];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_never_partially_write(array $changes, string $field): void
    {
        [$org, $user, $contract] = $this->documentScenario();
        $this->actingAsWithMfa($user);
        $this->patchJson($this->path($org, $contract), [...$this->settings($contract), ...$changes])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('contract_reminder_plans', 0);
    }

    public function test_foreign_contracts_and_read_only_accounts_cannot_configure_reminders(): void
    {
        [$org, $user, $contract] = $this->documentScenario();
        $this->actingAsWithMfa($user);
        $foreign = ContractRecord::factory()->create();
        $this->getJson($this->path($org, $foreign))->assertNotFound();
        $this->patchJson($this->path($org, $foreign), $this->settings($foreign))->assertNotFound();
        $reader = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $reader, ['administrative.read']);
        $this->actingAsWithMfa($reader);
        $this->getJson($this->path($org, $contract))->assertOk();
        $this->patchJson($this->path($org, $contract), $this->settings($contract))->assertForbidden();
    }

    public function test_worker_notifies_both_channels_once_per_threshold_even_after_relaunch(): void
    {
        [$org, $user, $contract] = $this->scenario(90);
        $run = (string) Str::uuid();
        $this->processReminders($org, $run);
        $this->processReminders($org, $run);
        $this->travel(16)->minutes();
        $this->processReminders($org);
        $this->assertDatabaseCount('contract_reminder_deliveries', 1);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame($user->email, $this->mailer->sent[0]['email']);
        $this->travel(60)->days();
        $this->processReminders($org);
        $this->assertDatabaseCount('contract_reminder_deliveries', 2);
        $this->assertCount(2, $this->mailer->sent);
        $this->actingAsWithMfa($user);
        $this->getJson('/api/v1/tenants/'.$org->id.'/contracts/reminders')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.recipient_id')->assertJsonMissingPath('data.0.occurrence');
        $this->getJson($this->path($org, $contract).'/history?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
    }

    public function test_catch_up_chooses_only_the_most_urgent_threshold_and_never_past_dates(): void
    {
        [$org, , $contract, $context] = $this->scenario(25);
        $plan = ContractReminderPlan::query()->firstOrFail();
        $plan->forceFill(['notice_on' => now()->addDays(5)->toDateString(), 'renewal_on' => now()->subDay()->toDateString()])->save();
        $this->processReminders($org);
        $this->assertDatabaseCount('contract_reminder_deliveries', 2);
        $this->assertDatabaseHas('contract_reminder_deliveries', ['kind' => 'deadline', 'offset_days' => 30]);
        $this->assertDatabaseHas('contract_reminder_deliveries', ['kind' => 'notice', 'offset_days' => 7]);
        $this->assertDatabaseMissing('contract_reminder_deliveries', ['kind' => 'renewal']);
        $this->assertCount(2, $this->mailer->sent);
    }

    public function test_calendar_threshold_uses_tenant_timezone_across_dst(): void
    {
        $plan = new ContractReminderPlan;
        $plan->forceFill(['enabled' => true, 'offsets' => [7], 'notice_on' => null, 'renewal_on' => null]);
        $contract = new ContractRecord;
        $contract->forceFill(['status' => 'approved', 'ends_on' => '2026-11-02']);
        $this->travelTo(CarbonImmutable::parse('2026-10-25T23:30:00Z'));
        $this->assertSame(7, ContractReminderSchedule::occurrences($plan, $contract, 'Europe/Paris')['deadline']['offset_days']);
        $this->assertSame([], ContractReminderSchedule::occurrences($plan, $contract, 'America/Los_Angeles'));
    }

    public function test_draft_contracts_do_not_generate_reminders(): void
    {
        [$org, $user, $contract, $context] = $this->documentScenario();
        $contract->forceFill(['ends_on' => now()->addDays(7)->toDateString()])->save();
        app(ContractReminderService::class)->save($context, $contract->id, $this->settings($contract));
        $this->processReminders($org);
        $this->assertDatabaseCount('contract_reminder_deliveries', 0);
        $this->assertCount(0, $this->mailer->sent);
    }

    public function test_missing_account_blocks_delivery_without_using_private_contact_email(): void
    {
        [$org, , $contract] = $this->scenario(30, false);
        $this->processReminders($org);
        $this->assertDatabaseHas('contract_reminder_deliveries', ['in_app_status' => 'blocked', 'email_status' => 'blocked', 'reason' => 'recipient_unavailable']);
        $this->assertCount(0, $this->mailer->sent);
    }

    public function test_revoked_permissions_hide_inbox_and_cancel_pending_mail_then_recover_safely(): void
    {
        [$org, $user, $contract] = $this->scenario();
        $this->mailer->enabled = false;
        $this->processReminders($org);
        $this->assertDatabaseHas('contract_reminder_deliveries', ['in_app_status' => 'sent', 'email_status' => 'pending', 'reason' => 'smtp_unavailable']);
        OrganizationRoleAssignment::query()->where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->mailer->enabled = true;
        $this->processReminders($org);
        $this->assertDatabaseHas('contract_reminder_deliveries', ['email_status' => 'blocked', 'reason' => 'recipient_unavailable']);
        $this->actingAsWithMfa($user);
        $this->getJson('/api/v1/tenants/'.$org->id.'/contracts/reminders')->assertForbidden();
        $this->assertCount(0, $this->mailer->sent);
        OrganizationRoleAssignment::query()->where('user_id', $user->id)->update(['status' => 'active']);
        $this->travel(16)->minutes();
        $this->processReminders($org);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertDatabaseCount('contract_reminder_deliveries', 1);
    }

    public function test_smtp_ambiguity_and_worker_crash_never_automatically_resend(): void
    {
        [$org, $user, $contract] = $this->scenario();
        $this->mailer->fail = true;
        $this->processReminders($org);
        $this->assertDatabaseHas('contract_reminder_deliveries', ['email_status' => 'uncertain', 'attempts' => 1]);
        $this->travel(16)->minutes();
        $this->mailer->fail = false;
        $this->processReminders($org);
        $this->assertCount(1, $this->mailer->sent);
        $delivery = ContractReminderDelivery::query()->firstOrFail();
        $delivery->forceFill(['email_status' => 'sending', 'email_started_at' => now()->subMinutes(6), 'version' => (string) Str::uuid()])->save();
        $this->processReminders($org);
        $this->assertSame('uncertain', $delivery->refresh()->email_status);
        $this->assertCount(1, $this->mailer->sent);
        $this->actingAsWithMfa($user);
        $path = $this->path($org, $contract).'/'.$delivery->id.'/resolution';
        $this->postJson($path, ['expected_version' => $delivery->version, 'action' => 'retry'])->assertUnprocessable();
        $this->postJson($path, ['expected_version' => $delivery->version, 'action' => 'retry', 'confirm_possible_duplicate' => true])->assertOk();
        $this->processReminders($org);
        $this->assertCount(2, $this->mailer->sent);
        $this->assertDatabaseHas('contract_reminder_deliveries', ['email_status' => 'sent', 'attempts' => 2]);
    }

    public function test_confirming_provider_acceptance_does_not_send_another_email(): void
    {
        [$org, $user, $contract] = $this->scenario();
        $this->mailer->fail = true;
        $this->processReminders($org);
        $delivery = ContractReminderDelivery::query()->firstOrFail();
        $this->actingAsWithMfa($user);
        $this->postJson($this->path($org, $contract).'/'.$delivery->id.'/resolution',
            ['expected_version' => $delivery->version, 'action' => 'confirm_sent'])->assertOk();
        $this->travel(16)->minutes();
        $this->processReminders($org);
        $this->assertCount(1, $this->mailer->sent);
    }

    public function test_financial_access_is_rechecked_for_settings_history_and_inbox(): void
    {
        [$org, $user, $contract] = $this->scenario(30, true, true);
        $this->mailer->enabled = false;
        $this->processReminders($org);
        $assignment = OrganizationRoleAssignment::query()->where('user_id', $user->id)->firstOrFail();
        OrganizationRole::query()->findOrFail($assignment->role_id)
            ->forceFill(['permissions' => ['administrative.read', 'administrative.write', 'administrative.validate'], 'version' => (string) Str::uuid()])->save();
        $this->actingAsWithMfa($user);
        $this->getJson($this->path($org, $contract))->assertForbidden();
        $this->getJson($this->path($org, $contract).'/history')->assertForbidden();
        $this->getJson('/api/v1/tenants/'.$org->id.'/contracts/reminders')->assertOk()->assertJsonCount(0, 'data');
        $this->mailer->enabled = true;
        $this->processReminders($org);
        $this->assertCount(0, $this->mailer->sent);
        $this->assertDatabaseHas('contract_reminder_deliveries', ['email_status' => 'blocked', 'reason' => 'recipient_unavailable']);
    }

    public function test_history_has_bounded_queries_and_database_guards_preserve_identity(): void
    {
        [$org, $user, $contract] = $this->scenario();
        $this->processReminders($org);
        $this->actingAsWithMfa($user);
        $path = $this->path($org, $contract).'/history';
        $this->getJson($path)->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($path)->assertOk();
        $one = count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains(strtolower($query['query']), 'from "contract_reminder_deliveries"')));
        DB::disableQueryLog();
        $this->travel(23)->days();
        $this->processReminders($org);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($one, count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains(strtolower($query['query']), 'from "contract_reminder_deliveries"'))));
        DB::disableQueryLog();
        $delivery = ContractReminderDelivery::query()->firstOrFail();
        try {
            DB::transaction(fn () => DB::table('contract_reminder_deliveries')->where('id', $delivery->id)->update(['person_id' => (string) Str::uuid(), 'version' => (string) Str::uuid()]));
            $this->fail('Reminder identity must be retained.');
        } catch (QueryException) {
            $this->assertDatabaseHas('contract_reminder_deliveries', ['id' => $delivery->id, 'person_id' => $contract->responsible_person_id]);
        }
        $migration = require database_path('migrations/2026_10_15_184218_create_contract_reminders.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_reassignment_removes_old_inbox_access_and_never_sends_to_the_previous_responsible(): void
    {
        [$org, $user, $contract] = $this->scenario();
        $this->mailer->enabled = false;
        $this->processReminders($org);
        $replacement = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $replacement, ['administrative.read']);
        $person = Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $org->id, 'user_id' => $replacement->id, 'status' => 'active']);
        $contract->forceFill(['responsible_person_id' => $person->id, 'responsible_name' => 'Autre responsable', 'version' => (string) Str::uuid()])->save();
        $this->actingAsWithMfa($user);
        $this->getJson('/api/v1/tenants/'.$org->id.'/contracts/reminders')->assertOk()->assertJsonCount(0, 'data');
        $this->mailer->enabled = true;
        $this->travel(16)->minutes();
        $this->processReminders($org);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame($replacement->email, $this->mailer->sent[0]['email']);
        $this->actingAsWithMfa($replacement);
        $this->getJson('/api/v1/tenants/'.$org->id.'/contracts/reminders')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('contract_reminder_deliveries', 2);
        $this->assertDatabaseHas('contract_reminder_deliveries', ['recipient_id' => $user->id, 'email_status' => 'blocked', 'reason' => 'obsolete']);
    }

    public function test_mfa_is_rechecked_before_email_delivery(): void
    {
        [$org, $user] = $this->scenario();
        $this->mailer->enabled = false;
        $this->processReminders($org);
        $user->forceFill(['mfa_confirmed_at' => null])->save();
        $this->mailer->enabled = true;
        $this->processReminders($org);
        $this->assertCount(0, $this->mailer->sent);
        $this->assertDatabaseHas('contract_reminder_deliveries', ['email_status' => 'blocked']);
    }

    public function test_settings_and_contract_version_roll_back_when_audit_fails(): void
    {
        [$org, $user, $contract, $context] = $this->documentScenario();
        $audit = \Mockery::mock(TenantAuditJournal::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        app()->instance(TenantAuditJournal::class, $audit);
        $version = $contract->version;
        try {
            app(ContractReminderService::class)->save($context, $contract->id, $this->settings($contract));
            $this->fail('Audit failure must roll back settings.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('contract_reminder_plans', 0);
        $this->assertSame($version, $contract->refresh()->version);
    }

    public function test_authorized_account_without_a_linked_person_has_an_empty_inbox(): void
    {
        [$org, $user] = $this->documentScenario();
        $this->actingAsWithMfa($user);
        $this->getJson('/api/v1/tenants/'.$org->id.'/contracts/reminders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_empty_reminder_migration_round_trip_preserves_existing_contracts(): void
    {
        $contract = ContractRecord::factory()->create();
        $migration = require database_path('migrations/2026_10_15_184218_create_contract_reminders.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
    }

    public function test_disabling_a_plan_cancels_pending_email_without_removing_history(): void
    {
        [$org, $user, $contract, $context] = $this->scenario();
        $this->mailer->enabled = false;
        $this->processReminders($org);
        $plan = ContractReminderPlan::query()->firstOrFail();
        app(ContractReminderService::class)->save($context, $contract->id, [...$this->settings($contract), 'expected_version' => $plan->version, 'enabled' => false]);
        $this->mailer->enabled = true;
        $this->processReminders($org);
        $this->assertCount(0, $this->mailer->sent);
        $this->assertDatabaseHas('contract_reminder_deliveries', ['email_status' => 'blocked', 'reason' => 'obsolete']);
        $this->assertDatabaseCount('contract_reminder_deliveries', 1);
    }

    /** @return array{Organization, User, ContractRecord, TenantContext} */
    private function scenario(int $days = 30, bool $linked = true, bool $financial = false): array
    {
        $permissions = ['administrative.read', 'administrative.write', 'administrative.validate', ...($financial ? ['financial.read', 'financial.write', 'financial.validate'] : [])];
        [$org, $user, $contract, $context] = $this->documentScenario($permissions);
        $contract->forceFill(['ends_on' => now()->addDays($days)->toDateString()])->save();
        if ($financial) {
            app(ContractFinancialService::class)->save($context, $contract->id, ['contract_version' => $contract->version, 'expected_version' => null,
                'terms' => ['currency' => 'EUR', 'remuneration' => '1000.00', 'remuneration_period' => 'monthly', 'bonus' => null, 'prize_share_percent' => null, 'payment_terms' => null]]);
            $contract->refresh();
        }
        if ($linked) {
            Person::query()->whereKey($contract->responsible_person_id)->firstOrFail()->forceFill(['user_id' => $user->id, 'status' => 'active'])->save();
        }
        app(ContractReminderService::class)->save($context, $contract->id, $this->settings($contract));
        $contract->refresh();
        app(ContractService::class)->transition($context, $contract->id, ['version' => $contract->version, 'status' => 'in_review']);
        $reviewer = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $reviewer, $permissions);
        $contract->refresh();
        app(ContractService::class)->transition($this->personContext($org, $reviewer), $contract->id, ['version' => $contract->version, 'status' => 'approved']);

        return [$org, $user, $contract->refresh(), $context];
    }

    private function processReminders(Organization $organization, ?string $run = null): void
    {
        $run ??= (string) Str::uuid();
        RunTenantScheduledTask::dispatchSync(new JobContext($run, $organization->id, null), 'contract-reminders', $run);
    }

    /** @return array<string,mixed> */
    private function settings(ContractRecord $contract): array
    {
        return ['contract_version' => $contract->version, 'expected_version' => null, 'enabled' => true,
            'notice_on' => null, 'renewal_on' => null, 'offsets' => [90, 30, 7], 'channels' => ['in_app', 'email']];
    }

    private function path(Organization $organization, ContractRecord $contract): string
    {
        return '/api/v1/tenants/'.$organization->id.'/contracts/'.$contract->id.'/reminders';
    }
}
