<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Services\ProcessCompetitionRegistrationReminders;
use App\Modules\Competition\Domain\Models\CompetitionRegistrationReminder;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Infrastructure\Queue\RunTenantScheduledTask;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use App\Support\Queue\JobContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesCompetitionRegistrations;
use Tests\Fixtures\ProvidesExternalCompetitions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CompetitionRegistrationReminderTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesCompetitionRegistrations,ProvidesExternalCompetitions,ProvidesPeopleAccess;

    /**
     * @param array<string,mixed> $s */
    private function runReminders(array $s): void
    {
        $run = (string) Str::uuid();
        app(ProcessCompetitionRegistrationReminders::class)->run(new TenantExecutionContext($s['org']->id, $run, 'UTC', 'fr'), $run);
    }

    public function test_reminders_use_most_urgent_stage_and_never_duplicate_after_retry_or_unrelated_edit(): void
    {
        $s = $this->registrationScenario(true);
        $this->runReminders($s);
        $this->runReminders($s);
        $this->assertDatabaseCount('competition_registration_reminders', 1);
        $this->getJson($s['path'].'/reminders')->assertOk()->assertJsonPath('data.0.stage', 'week')->assertJsonPath('meta.total', 1);
        $this->patchJson($s['path'], [...$s['input'], 'expected_version' => $s['revision']['version'], 'notes' => 'Note changée, même échéance et responsable.'])->assertOk();
        $this->runReminders($s);
        $this->assertDatabaseCount('competition_registration_reminders', 1);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        $this->runReminders($s);
        $this->assertDatabaseCount('competition_registration_reminders', 2);
        $this->travelTo(CarbonImmutable::parse('2026-10-21T10:00:00Z'));
        $this->runReminders($s);
        $this->runReminders($s);
        $this->assertDatabaseCount('competition_registration_reminders', 3);
        $this->getJson($s['path'])->assertOk()->assertJsonPath('data.summary.overdue', 1);
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['path'].'/reminders')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertStringNotContainsString('Pièce demandée', DB::table('competition_registration_reminders')->value('payload'));
    }

    public function test_completed_items_and_revoked_recipients_or_archived_competitions_receive_nothing(): void
    {
        $s = $this->registrationScenario();
        $input = $s['input'];
        $input['items'][0]['status'] = 'done';
        $revision = $this->patchJson($s['path'], $input)->assertOk()->json('data');
        $this->runReminders($s);
        $this->assertDatabaseCount('competition_registration_reminders', 0);
        $this->patchJson($s['path'], [...$s['input'], 'expected_version' => $revision['version']])->assertOk();
        $role = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->firstOrFail();
        $permissions = ['sports.read', 'sports.competitions.read', 'sports.competitions.write'];
        OrganizationRole::query()->findOrFail($role->role_id)->forceFill(['permissions' => $permissions])->save();
        $this->runReminders($s);
        $this->assertDatabaseCount('competition_registration_reminders', 0);
        $this->getJson($s['path'].'/reminders')->assertForbidden();
        $this->patchJson($s['root'].'/'.$s['record']['id'], ['version' => $s['record']['version'], 'archive' => true, 'reason' => 'Fin de participation conservée dans le dossier privé.'])->assertOk();
        $this->runReminders($s);
        $this->assertDatabaseCount('competition_registration_reminders', 0);
    }

    public function test_missed_deadline_generates_only_one_catchup_and_journal_is_immutable(): void
    {
        $s = $this->registrationScenario(true);
        $this->travelTo(CarbonImmutable::parse('2026-10-21T10:00:00Z'));
        $this->runReminders($s);
        $this->assertDatabaseCount('competition_registration_reminders', 1);
        $this->assertSame('overdue', CompetitionRegistrationReminder::query()->firstOrFail()->stage);
        $this->expectException(QueryException::class);
        DB::table('competition_registration_reminders')->delete();
    }

    #[Group('tenant-isolation')]
    public function test_serialized_scheduled_reminders_keep_two_tenants_isolated_after_http_switch_and_replay(): void
    {
        [$alpha, $beta] = $this->sharedRecipientScenarios();
        $this->selectOrganization($beta['org']->id);
        $run = (string) Str::uuid();
        $first = $this->scheduledTransport($alpha['org']->id, $run);
        $second = $this->scheduledTransport($beta['org']->id, $run);

        Bus::dispatchSync(unserialize($first));
        $this->assertDatabaseCount('competition_registration_reminders', 1);
        $this->assertSame(0, CompetitionRegistrationReminder::query()->where('organization_id', $beta['org']->id)->count());
        Bus::dispatchSync(unserialize($second));
        $before = $this->reminderSnapshot();
        Bus::dispatchSync(unserialize($first));
        Bus::dispatchSync(unserialize($second));
        Bus::dispatchSync(unserialize($this->scheduledTransport($alpha['org']->id, (string) Str::uuid())));
        Bus::dispatchSync(unserialize($this->scheduledTransport($beta['org']->id, (string) Str::uuid())));

        $this->assertSame($before, $this->reminderSnapshot());
        $this->assertDatabaseCount('competition_registration_reminders', 2);
        $a = CompetitionRegistrationReminder::query()->where('organization_id', $alpha['org']->id)->firstOrFail();
        $b = CompetitionRegistrationReminder::query()->where('organization_id', $beta['org']->id)->firstOrFail();
        $this->assertSame($alpha['writer']->id, $a->recipient_id);
        $this->assertSame($alpha['writer']->id, $b->recipient_id);
        $this->assertNotSame($a->occurrence, $b->occurrence);
        $this->assertSame($alpha['revision']['version'], $a->revision_id);
        $this->assertSame($beta['revision']['version'], $b->revision_id);
        $this->assertSame($beta['org']->id, session()->get(ActiveOrganizationSession::KEY.'.organization_id'));
        $this->assertFalse(app(TenantExecutionScope::class)->active());
        $this->getJson($beta['path'].'/reminders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);
        $this->selectOrganization($alpha['org']->id);
        $this->getJson($alpha['path'].'/reminders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership'])]
    #[TestWith(['role'])]
    #[TestWith(['read'])]
    public function test_beta_permissions_cannot_authorize_alpha_reminder_generation_after_revocation(string $revocation): void
    {
        [$alpha, $beta] = $this->sharedRecipientScenarios();
        $this->getJson($alpha['path'])->assertOk();
        $run = (string) Str::uuid();
        $first = $this->scheduledTransport($alpha['org']->id, $run);
        $second = $this->scheduledTransport($beta['org']->id, $run);
        $this->selectOrganization($beta['org']->id);
        if ($revocation === 'membership') {
            OrganizationMembership::query()->where('organization_id', $alpha['org']->id)->where('user_id', $alpha['writer']->id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        } else {
            $assignment = OrganizationRoleAssignment::query()->where('organization_id', $alpha['org']->id)->where('user_id', $alpha['writer']->id)->firstOrFail();
            if ($revocation === 'role') {
                $assignment->forceFill(['status' => 'revoked'])->save();
            } else {
                $assignment->role->forceFill(['permissions' => ['sports.read', 'sports.competitions.read', 'sports.competitions.write', 'sports.registrations.write']])->save();
            }
        }

        Bus::dispatchSync(unserialize($first));
        $this->assertDatabaseCount('competition_registration_reminders', 0);
        Bus::dispatchSync(unserialize($second));
        Bus::dispatchSync(unserialize($first));
        Bus::dispatchSync(unserialize($second));

        $this->assertDatabaseCount('competition_registration_reminders', 1);
        $this->assertSame(0, CompetitionRegistrationReminder::query()->where('organization_id', $alpha['org']->id)->count());
        $reminder = CompetitionRegistrationReminder::query()->firstOrFail();
        $this->assertSame($beta['org']->id, $reminder->organization_id);
        $this->assertSame($alpha['writer']->id, $reminder->recipient_id);
        $this->assertSame($beta['revision']['version'], $reminder->revision_id);
        $this->getJson($beta['path'].'/reminders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $reminder->id);
        $this->assertDatabaseCount('competition_registration_revisions', 2);
        $this->assertSame($beta['org']->id, session()->get(ActiveOrganizationSession::KEY.'.organization_id'));
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function sharedRecipientScenarios(): array
    {
        $alpha = $this->registrationScenario(true);
        $beta = $this->registrationScenario(true);
        $this->grantPeopleAccess($beta['org'], $alpha['writer'], ['sports.read', 'sports.competitions.read', 'sports.competitions.write', 'sports.registrations.read', 'sports.registrations.write']);
        $beta['manager']->forceFill(['user_id' => $alpha['writer']->id])->save();
        $this->actingAsWithMfa($alpha['writer']);

        return [$alpha, $beta];
    }

    private function scheduledTransport(string $organization, string $run): string
    {
        config(['tenancy.cache_store' => 'database', 'tenancy.scheduled_tasks' => ['competition-registration-reminders' => ProcessCompetitionRegistrationReminders::class]]);

        return serialize(new RunTenantScheduledTask(new JobContext($run, $organization, null), 'competition-registration-reminders', $run));
    }

    /** @return list<array<string, mixed>> */
    private function reminderSnapshot(): array
    {
        return array_values(DB::table('competition_registration_reminders')->orderBy('id')->get()->map(fn (object $row): array => get_object_vars($row))->all());
    }

    private function selectOrganization(string $organization): void
    {
        $revision = $this->postJson('/api/v1/active-organization', ['organization_id' => $organization])->assertOk()->json('data.revision');
        $this->withHeader('X-Tenant-Revision', $revision);
        $this->postJson('/api/v1/active-organization/confirmation', ['organization_id' => $organization, 'revision' => $revision, 'confirm' => true])->assertOk();
    }
}
