<?php

namespace Tests\Feature;

use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\AcademyObjective;
use App\Modules\Academy\Domain\Models\AcademySession;
use App\Modules\Academy\Domain\Models\Cohort;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarParticipant;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

#[Group('tenant-isolation')]
class ContextualSourceScopesTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string, array{string, string, string}> */
    public static function scopes(): array
    {
        return ['game' => ['game', 'sports.read', 'games.manage'], 'cohort' => ['cohort', 'academy.read', 'academy.manage'], 'event' => ['event', 'calendar.read', 'calendar.write']];
    }

    /** @param 'game'|'cohort'|'event' $type */
    #[DataProvider('scopes')]
    public function test_assignment_is_audited_scoped_and_effective_only_during_its_period(string $type, string $read, string $write): void
    {
        $this->freezeSecond();
        [$org, $owner, $subject, $id] = $this->scenario($type);
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id, 'permissions' => [$read, $write], 'privileged' => true]);
        $start = now()->addHour();
        $end = now()->addHours(2);
        $payload = ['role_id' => $role->id, 'user_id' => $subject->id, 'scope_type' => $type, 'scope_id' => $id,
            'status' => 'active', 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()];
        $this->actingAsWithMfa($owner);
        $grant = $this->postJson($this->root($org).'/role-assignments', $payload)->assertCreated()->json('data');
        $this->assertDatabaseHas('organization_access_events', ['action' => 'role_assignment.created', 'subject_id' => $grant['id']]);
        $context = $this->context($org, $subject);
        $auth = app(TenantAuthorization::class);
        $this->assertFalse($auth->allows($context, $read, $type, $id));
        $this->travelTo($start);
        $this->assertTrue($auth->allows($context, $read, $type, $id));
        $this->assertFalse($auth->allows($context, $read));
        $this->assertFalse($auth->allows($context, $read, $type, (string) Str::uuid()));
        $this->actingAsWithMfa($subject);
        $this->getJson($this->root($org).'/contextual-permissions')->assertOk()->assertJsonPath('data.permissions', [$read, $write]);
        $this->getJson($this->root($org).'/authorization-scopes/'.$type)->assertForbidden();
        $this->travelTo($end);
        $this->assertFalse($auth->allows($context, $read, $type, $id));
        $this->getJson($this->root($org).'/contextual-permissions')->assertOk()->assertJsonPath('data.permissions', []);
    }

    /** @param 'game'|'cohort'|'event' $type */
    #[DataProvider('scopes')]
    public function test_suspension_revocation_and_membership_end_remove_access_without_cached_rights(string $type, string $read, string $write): void
    {
        $this->freezeSecond();
        [$org, $owner, $subject, $id] = $this->scenario($type);
        $grant = $this->grant($org, $owner, $subject, $type, $id, [$read, $write]);
        $ctx = $this->context($org, $subject);
        $auth = app(TenantAuthorization::class);
        foreach (['suspended', 'active', 'revoked'] as $status) {
            $this->actingAsWithMfa($owner);
            $payload = ['role_id' => $grant->role_id, 'user_id' => $subject->id, 'scope_type' => $type, 'scope_id' => $id,
                'starts_at' => $grant->starts_at->toIso8601String(), 'ends_at' => null, 'version' => $grant->version, 'status' => $status];
            $this->putJson($this->root($org).'/role-assignments/'.$grant->id, $payload)->assertOk();
            $grant->refresh();
            $this->assertSame($status === 'active', $auth->allows($ctx, $read, $type, $id));
        }
        $this->putJson($this->root($org).'/role-assignments/'.$grant->id, [...$payload, 'status' => 'active', 'version' => $grant->version])->assertConflict();
        $replacement = $this->grant($org, $owner, $subject, $type, $id, [$read]);
        $membership = OrganizationMembership::query()->where('id', $replacement->membership_id)->firstOrFail();
        $membership->forceFill(['ends_at' => now()])->save();
        $this->assertFalse($auth->allows($ctx, $read, $type, $id));
    }

    public function test_cohort_pages_counts_nested_writes_and_history_are_restricted_to_the_granted_parent(): void
    {
        [$org,$owner,$subject,$id] = $this->scenario('cohort');
        $hidden = Cohort::factory()->create(['organization_id' => $org->id, 'name' => 'PRIVATE_OTHER_COHORT']);
        $this->grant($org, $owner, $subject, 'cohort', $id, ['academy.read', 'academy.manage']);
        $this->actingAsWithMfa($subject);
        $path = $this->root($org).'/academy/cohorts';
        $this->getJson($path.'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id)->assertDontSee('PRIVATE_OTHER_COHORT');
        $this->postJson($path, ['name' => 'Unauthorized new cohort'])->assertForbidden();
        $this->patchJson($path.'/'.$hidden->id, ['version' => $hidden->version, 'name' => 'Attack'])->assertForbidden();
        $this->getJson($path.'/'.$hidden->id.'/cycles')->assertForbidden();
        $cohort = Cohort::query()->whereKey($id)->firstOrFail();
        $this->patchJson($path.'/'.$id, ['version' => $cohort->version, 'name' => 'Cohorte autorisée'])->assertOk()->assertJsonPath('data.name', 'Cohorte autorisée');
        $cycle = $this->postJson($path.'/'.$id.'/cycles', ['name' => 'Cycle limité', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'timezone' => 'UTC', 'timetable' => []])->assertCreated()->json('data');
        $this->getJson($path.'/'.$id.'/cycles')->assertOk()->assertJsonPath('data.0.id', $cycle['id']);
        $this->getJson($path.'/'.$id.'/history')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->root($org).'/academy/people')->assertForbidden();
        $this->assertDatabaseHas('academy_cohorts', ['id' => $hidden->id, 'name' => 'PRIVATE_OTHER_COHORT']);
        $this->assertDatabaseCount('academy_cycles', 1);
    }

    public function test_event_read_pages_and_mutations_do_not_disclose_other_private_events(): void
    {
        [$org,$owner,$subject,$id] = $this->scenario('event');
        $event = CalendarActivity::query()->whereKey($id)->firstOrFail();
        $hidden = CalendarActivity::factory()->create(['organization_id' => $org->id, 'title' => 'PRIVATE_OTHER_EVENT']);
        $this->grant($org, $owner, $subject, 'event', $id, ['calendar.read', 'calendar.write']);
        $this->actingAsWithMfa($subject);
        $path = $this->root($org).'/calendar/activities';
        $window = '?from_date='.$event->starts_at->toDateString().'&to_date='.$event->ends_at->addDay()->toDateString();
        $this->getJson($path.$window.'&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id)->assertDontSee('PRIVATE_OTHER_EVENT');
        $this->getJson($path.'/'.$hidden->id)->assertNotFound();
        $this->patchJson($path.'/'.$hidden->id, ['version' => $hidden->version, 'title' => 'Attack'])->assertForbidden();
        $this->postJson($path, ['title' => 'Forbidden', 'kind' => 'training', 'starts_at' => $event->starts_at->toIso8601String(), 'ends_at' => $event->ends_at->toIso8601String(), 'origin_timezone' => 'UTC'])->assertForbidden();
        $this->patchJson($path.'/'.$id, ['version' => $event->version, 'title' => 'Titre autorisé'])->assertOk()->assertJsonPath('data.title', 'Titre autorisé');
        $this->assertSame('PRIVATE_OTHER_EVENT', $hidden->refresh()->title);
        $this->getJson($this->root($org).'/calendar/people')->assertForbidden();
    }

    public function test_game_pages_filter_before_totals_and_selected_settings_remain_versioned(): void
    {
        [$org,$owner,$subject,$id] = $this->scenario('game');
        $this->grant($org, $owner, $subject, 'game', $id, ['sports.read', 'games.manage']);
        $this->actingAsWithMfa($subject);
        $path = $this->root($org).'/games';
        $this->getJson($path.'?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->getJson($path.'/'.GameCatalogueEntry::query()->where('kind', 'game')->where('id', '!=', $id)->firstOrFail()->id)->assertForbidden();
        $payload = ['version' => null, 'catalogue_version' => DB::table('game_catalogue_state')->value('version'), 'enabled' => true, 'restrictions' => ['platform' => [], 'region' => [], 'provider' => []]];
        $this->patchJson($path.'/'.$id, $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->patchJson($path.'/'.$id, $payload)->assertConflict();
        $this->assertDatabaseHas('organization_games', ['organization_id' => $org->id, 'game_id' => $id]);
    }

    public function test_scope_choices_require_source_rights_are_bounded_and_project_decrypted_minimal_labels(): void
    {
        [$org,$owner] = $this->scenario('event');
        $this->actingAsWithMfa($owner);
        $path = $this->root($org).'/authorization-scopes/event';
        $this->getJson($path)->assertForbidden();
        $ownerRole = OrganizationRole::factory()->create(['organization_id' => $org->id, 'permissions' => ['calendar.read', 'calendar.write'], 'privileged' => true]);
        OrganizationRoleAssignment::factory()->create(['organization_id' => $org->id, 'user_id' => $owner->id, 'membership_id' => null, 'role_id' => $ownerRole->id, 'scope_type' => 'organization', 'scope_id' => $org->id]);
        $this->getJson($path.'?per_page=101')->assertUnprocessable();
        CalendarActivity::factory()->create(['title' => 'FOREIGN_EVENT']);
        $this->getJson($path.'?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.label', 'Private limited event')->assertJsonStructure(['data' => [['id', 'label']]])->assertJsonMissingPath('data.0.title')->assertDontSee('FOREIGN_EVENT');
        $this->getJson($this->root($org).'/authorization-scopes/unknown')->assertNotFound();
    }

    public function test_unsupported_permissions_and_foreign_sources_are_rejected_without_writing_assignments(): void
    {
        [$org,$owner,$subject,$id] = $this->scenario('cohort');
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id, 'permissions' => ['financial.read'], 'privileged' => true]);
        $this->actingAsWithMfa($owner);
        $data = ['role_id' => $role->id, 'user_id' => $subject->id, 'scope_type' => 'cohort', 'scope_id' => $id, 'status' => 'active', 'starts_at' => now()->toIso8601String(), 'ends_at' => null];
        $this->postJson($this->root($org).'/role-assignments', $data)->assertConflict()->assertJsonPath('code', 'ROLE_SCOPE_INCOMPATIBLE');
        $role->forceFill(['permissions' => ['academy.read']])->save();
        $foreign = Cohort::factory()->create();
        $this->postJson($this->root($org).'/role-assignments', [...$data, 'scope_id' => $foreign->id])->assertConflict()->assertJsonPath('code', 'AUTHORIZATION_SCOPE_UNAVAILABLE');
        $this->assertDatabaseCount('organization_role_assignments', 0);
    }

    public function test_archived_cohort_removes_scoped_access_but_can_still_be_revoked(): void
    {
        [$org,$owner,$subject,$id] = $this->scenario('cohort');
        $grant = $this->grant($org, $owner, $subject, 'cohort', $id, ['academy.read', 'academy.manage']);
        $this->actingAsWithMfa($subject);
        $this->patchJson($this->root($org).'/academy/cohorts/'.$id, ['version' => Cohort::query()->whereKey($id)->firstOrFail()->version, 'status' => 'archived'])->assertOk();
        $this->assertFalse(app(TenantAuthorization::class)->allows($this->context($org, $subject), 'academy.read', 'cohort', $id));
        $this->actingAsWithMfa($owner);
        $this->putJson($this->root($org).'/role-assignments/'.$grant->id, ['role_id' => $grant->role_id, 'user_id' => $subject->id, 'scope_type' => 'cohort', 'scope_id' => $id, 'status' => 'revoked', 'starts_at' => $grant->starts_at->toIso8601String(), 'ends_at' => null, 'version' => $grant->version])->assertOk();
    }

    public function test_cohort_scopes_cover_sessions_coaching_and_enrollments_without_opening_other_cohorts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00'));
        [$org,$owner,$subject,$id] = $this->scenario('cohort');
        $cycle = AcademyCycle::factory()->create(['organization_id' => $org->id, 'cohort_id' => $id]);
        $mentor = AcademyEnrollment::factory()->create(['cycle_id' => $cycle->id, 'role' => 'mentor']);
        $session = AcademySession::factory()->create(['responsible_enrollment_id' => $mentor->id]);
        $objective = AcademyObjective::factory()->create(['responsible_enrollment_id' => $mentor->id]);
        $otherCohort = Cohort::factory()->create(['organization_id' => $org->id]);
        $other = AcademyCycle::factory()->create(['organization_id' => $org->id, 'cohort_id' => $otherCohort->id]);
        $this->grant($org, $owner, $subject, 'cohort', $id, ['academy.read', 'academy.manage', 'academy.notes.manage', 'academy.feedback.manage', 'academy.attendance.manage']);
        // Reading or assigning calendar activities is a separate permission.
        $this->actingAsWithMfa($subject);
        $path = $this->root($org).'/academy/cohorts/'.$id.'/cycles/'.$cycle->id;
        $this->getJson($path.'/sessions')->assertOk()->assertJsonPath('data.0.id', $session->id)->assertJsonPath('data.0.schedule_state', 'unavailable');
        $this->getJson($path.'/sessions/'.$session->id.'/history')->assertOk();
        $this->getJson($path.'/objectives')->assertOk()->assertJsonPath('data.0.id', $objective->id);
        $this->getJson($path.'/objectives/'.$objective->id.'/notes')->assertOk();
        $this->postJson($path.'/objectives/'.$objective->id.'/notes', ['content' => 'Note limitée à cette cohorte', 'justification' => 'Justification limitée à cette cohorte'])->assertCreated();
        $this->getJson($this->root($org).'/academy/cohorts/'.$other->cohort_id.'/cycles/'.$other->id.'/sessions')->assertForbidden();
        $this->getJson($this->root($org).'/academy/cohorts/'.$id.'/cycles/'.$other->id.'/objectives')->assertNotFound();
        $this->getJson($this->root($org).'/academy/cohorts/'.$id.'/enrollments')->assertOk()->assertJsonPath('data.0.id', $mentor->id);
        $this->assertDatabaseCount('academy_feedback', 1);
    }

    public function test_scoped_event_conflict_review_never_exposes_a_hidden_overlapping_event(): void
    {
        [$org,$owner,$subject,$id] = $this->scenario('event');
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $event = CalendarActivity::query()->whereKey($id)->firstOrFail();
        $event->forceFill(['team_id' => $team->id])->save();
        CalendarActivity::factory()->create(['organization_id' => $org->id, 'team_id' => $team->id, 'title' => 'HIDDEN_CONFLICT_TITLE',
            'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at]);
        $this->grant($org, $owner, $subject, 'event', $id, ['calendar.read', 'calendar.write']);
        $this->actingAsWithMfa($subject);
        $this->patchJson($this->root($org).'/calendar/activities/'.$id, ['version' => $event->version, 'title' => 'Title changed'])
            ->assertConflict()->assertDontSee('HIDDEN_CONFLICT_TITLE')->assertJsonPath('code', 'CALENDAR_CONFLICT_REVIEW_REQUIRED');
        $this->assertSame('Private limited event', $event->refresh()->title);
    }

    public function test_event_cancellation_prepares_recipients_before_closing_the_scope_and_retains_history(): void
    {
        [$org,$owner,$subject,$id] = $this->scenario('event');
        $event = CalendarActivity::query()->whereKey($id)->firstOrFail();
        $person = Person::factory()->create(['organization_id' => $org->id]);
        CalendarParticipant::factory()->create(['organization_id' => $org->id, 'activity_id' => $id, 'person_id' => $person->id]);
        $this->grant($org, $owner, $subject, 'event', $id, ['calendar.read', 'calendar.write']);
        $this->actingAsWithMfa($subject);
        $this->patchJson($this->root($org).'/calendar/activities/'.$id, ['version' => $event->version, 'archive' => true, 'change_reason' => 'Annulation demandée par le responsable limité'])->assertOk();
        $this->assertNotNull($event->refresh()->cancelled_at);
        $this->assertDatabaseCount('calendar_changes', 1);
        $this->assertDatabaseCount('calendar_change_recipients', 1);
        $this->assertFalse(app(TenantAuthorization::class)->allows($this->context($org, $subject), 'calendar.read', 'event', $id));
    }

    /** @param 'game'|'cohort'|'event' $type
     * @return array{Organization, User, User, string} */
    private function scenario(string $type): array
    {
        $owner = User::factory()->withMfa()->create();
        $org = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $subject = User::factory()->withMfa()->create();
        OrganizationMembership::factory()->create(['organization_id' => $org->id, 'user_id' => $subject->id]);
        $id = match ($type) {
            'game' => GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'valorant')->firstOrFail()->id,
            'cohort' => Cohort::factory()->create(['organization_id' => $org->id])->id,
            'event' => CalendarActivity::factory()->create(['organization_id' => $org->id, 'created_by' => $owner->id, 'title' => 'Private limited event'])->id,
        };

        return [$org, $owner, $subject, $id];
    }

    /** @param list<string> $permissions */
    private function grant(Organization $org, User $owner, User $subject, string $type, string $id, array $permissions): OrganizationRoleAssignment
    {
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id, 'permissions' => $permissions, 'privileged' => true]);
        $this->actingAsWithMfa($owner);
        $data = $this->postJson($this->root($org).'/role-assignments', ['role_id' => $role->id, 'user_id' => $subject->id, 'scope_type' => $type, 'scope_id' => $id, 'status' => 'active', 'starts_at' => now()->toIso8601String(), 'ends_at' => null])->assertCreated()->json('data');

        return OrganizationRoleAssignment::query()->whereKey($data['id'])->firstOrFail();
    }

    /** @return array<string, array{bool}> */
    public static function mixedCalendarPermissions(): array
    {
        return ['global read with limited write' => [true], 'limited read with global write' => [false]];
    }

    #[DataProvider('mixedCalendarPermissions')]
    public function test_mixed_calendar_permissions_cannot_open_management_for_other_private_events(bool $globalRead): void
    {
        [$org, $owner, $subject, $id] = $this->scenario('event');
        $event = CalendarActivity::query()->whereKey($id)->firstOrFail();
        $hidden = CalendarActivity::factory()->create(['organization_id' => $org->id, 'title' => 'PRIVATE_UNASSIGNED']);
        CalendarActivity::factory()->shared()->create(['organization_id' => $org->id, 'title' => 'PUBLIC_READER_EVENT']);
        $this->organizationGrant($org, $subject, [$globalRead ? 'calendar.read' : 'calendar.write']);
        $this->grant($org, $owner, $subject, 'event', $id, $globalRead ? ['calendar.read', 'calendar.write'] : ['calendar.read']);
        $this->actingAsWithMfa($subject);
        $path = $this->root($org).'/calendar/activities';
        $window = '?from_date='.$event->starts_at->toDateString().'&to_date='.$event->ends_at->addDay()->toDateString();
        $this->getJson($path.$window)->assertOk()->assertJsonPath('meta.total', $globalRead ? 2 : 1)->assertDontSee('PRIVATE_UNASSIGNED');
        $this->getJson($path.$window.'&management=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id)->assertDontSee('PUBLIC_READER_EVENT');
        $this->getJson($path.'/'.$hidden->id.'?management=1')->assertNotFound()->assertDontSee('PRIVATE_UNASSIGNED');
        $this->getJson($path.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_assignment_reference_picker_respects_private_visibility_for_read_only_iam_delegates(): void
    {
        [$org, $owner, $subject, $id] = $this->scenario('event');
        $this->organizationGrant($org, $subject, ['roles.assign', 'calendar.read']);
        $own = CalendarActivity::factory()->create(['organization_id' => $org->id, 'created_by' => $subject->id, 'title' => 'Own private event']);
        $this->actingAsWithMfa($subject);
        $path = $this->root($org).'/authorization-scopes/event';
        $this->getJson($path)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own->id)->assertDontSee('Private limited event');
        $this->grant($org, $owner, $subject, 'event', $id, ['calendar.read']);
        $this->actingAsWithMfa($subject);
        $this->getJson($path)->assertOk()->assertJsonPath('meta.total', 2)->assertSee('Private limited event');
    }

    /** @param list<string> $permissions */
    private function organizationGrant(Organization $org, User $user, array $permissions): void
    {
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id, 'permissions' => $permissions, 'privileged' => true]);
        $membership = OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $user->id)->firstOrFail();
        OrganizationRoleAssignment::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'role_id' => $role->id,
            'membership_id' => $membership->id, 'scope_type' => 'organization', 'scope_id' => $org->id]);
    }

    public function test_calendar_delegation_cannot_convert_global_public_read_into_an_unbounded_private_event_grant(): void
    {
        $this->freezeSecond();
        [$org, $owner, $delegate, $id] = $this->scenario('event');
        $target = User::factory()->withMfa()->create();
        OrganizationMembership::factory()->create(['organization_id' => $org->id, 'user_id' => $target->id]);
        $this->organizationGrant($org, $delegate, ['roles.assign', 'calendar.read']);
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id, 'permissions' => ['calendar.read'], 'privileged' => true]);
        $path = $this->root($org).'/role-assignments';
        $payload = ['role_id' => $role->id, 'user_id' => $target->id, 'scope_type' => 'event', 'scope_id' => $id,
            'status' => 'active', 'starts_at' => now()->toIso8601String(), 'ends_at' => null];
        $this->actingAsWithMfa($delegate);
        $this->postJson($path, $payload)->assertConflict()->assertJsonPath('code', 'ROLE_DELEGATION_DENIED');
        $limited = $this->grant($org, $owner, $delegate, 'event', $id, ['calendar.read']);
        $limited->forceFill(['ends_at' => now()->addHour()])->save();
        $this->actingAsWithMfa($delegate);
        $this->postJson($path, $payload)->assertConflict()->assertJsonPath('code', 'ROLE_DELEGATION_DENIED');
        $saved = $this->postJson($path, [...$payload, 'ends_at' => now()->addMinutes(50)->toIso8601String()])->assertCreated()->json('data');
        $this->organizationGrant($org, $delegate, ['calendar.write']);
        $this->putJson($path.'/'.$saved['id'], [...$payload, 'version' => $saved['version']])->assertOk()->assertJsonPath('data.ends_at', null);
        $this->assertDatabaseHas('organization_role_assignments', ['id' => $saved['id'], 'scope_type' => 'event', 'scope_id' => $id, 'ends_at' => null]);
    }

    private function root(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id;
    }

    private function context(Organization $org, User $user): TenantContext
    {
        return new TenantContext($org->id, $user->id, $org->slug, 'UTC', 'fr', (string) Str::uuid());
    }
}
