<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Services\ProcessCompetitionRegistrationReminders;
use App\Modules\Competition\Domain\Models\CompetitionRegistrationReminder;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
}
