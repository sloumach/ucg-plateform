<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\Cohort;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesAcademySessions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class AcademySessionTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesAcademySessions, ProvidesPeopleAccess;

    public function test_session_stores_private_content_and_audits_without_granting_mentor_rights(): void
    {
        $s = $this->sessionScenario();
        $created = $this->postJson($s['path'], $s['body'])->assertCreated()->assertJsonPath('data.responsible_label', 'Mentor pilote')
            ->assertJsonPath('data.schedule_state', 'current')->assertJsonPath('data.starts_at', '2026-10-22T10:00:00+00:00')
            ->assertJsonPath('notification.message', 'La séance et son suivi ont été enregistrés.')->assertJsonMissingPath('data.organization_id');
        $raw = DB::table('academy_sessions')->where('id', $created->json('data.id'))->firstOrFail();
        $this->assertStringNotContainsString('Exercice privé', $raw->exercises);
        $this->assertStringNotContainsString('Compte rendu', $raw->report);
        $this->assertDatabaseCount('academy_sessions', 1);
        $this->assertDatabaseCount('academy_session_revisions', 1);
        $this->assertDatabaseCount('calendar_activities', 1);
        $this->assertDatabaseMissing('organization_role_assignments', ['user_id' => $s['mentor_user']->id]);
        $audit = json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Consigne technique', $audit);
        $this->getJson($s['path'])->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_guest_and_owner_have_no_implicit_session_access(): void
    {
        $org = Organization::factory()->create();
        $cycle = AcademyCycle::factory()->create(['cohort_id' => Cohort::factory()->create(['organization_id' => $org->id])->id]);
        $path = '/api/v1/tenants/'.$org->id.'/academy/cohorts/'.$cycle->cohort_id.'/cycles/'.$cycle->id.'/sessions';
        $this->getJson($path)->assertUnauthorized();
        $this->actingAsWithMfa(User::query()->findOrFail($org->owner_user_id))->getJson($path)->assertForbidden();
    }

    public function test_presence_writer_requires_a_distinct_permission_and_calendar_read(): void
    {
        $s = $this->sessionScenario(['academy.read', 'academy.manage', 'calendar.read']);
        $saved = $this->postJson($s['path'], $s['body'])->assertCreated();
        $this->postJson($s['path'].'/'.$saved->json('data.id').'/attendances', ['enrollment_id' => $s['student']->id, 'status' => 'present'])->assertForbidden();
        $this->assertDatabaseCount('academy_attendances', 0);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unavailableActivities(): array
    {
        return ['private' => [['visibility' => 'private', 'sharing_authorized_by' => null, 'sharing_authorized_at' => null]], 'team' => [['visibility' => 'team']], 'wrong kind' => [['kind' => 'training']],
            'cancelled' => [['cancelled_at' => '2026-10-22 09:00:00', 'archived_at' => '2026-10-22 09:00:00']], 'archived' => [['archived_at' => '2026-10-22 09:00:00']]];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('unavailableActivities')]
    public function test_unavailable_calendar_links_return_404_without_partial_records(array $changes): void
    {
        $s = $this->sessionScenario();
        if (($changes['visibility'] ?? null) === 'team') {
            $changes['team_id'] = Team::factory()->create(['organization_id' => $s['org']->id])->id;
        }
        $s['activity']->forceFill($changes)->save();
        $this->postJson($s['path'], $s['body'])->assertNotFound();
        $this->assertDatabaseCount('academy_sessions', 0);
        $this->assertDatabaseCount('academy_session_revisions', 0);
    }

    public function test_foreign_activity_and_wrong_cycle_mentor_are_refused(): void
    {
        $s = $this->sessionScenario();
        $foreign = CalendarActivity::factory()->shared()->create(['kind' => 'academy']);
        $this->postJson($s['path'], [...$s['body'], 'activity_id' => $foreign->id, 'activity_version' => $foreign->version])->assertNotFound();
        $mentor = AcademyEnrollment::factory()->create(['role' => 'mentor']);
        $this->postJson($s['path'], [...$s['body'], 'responsible_enrollment_id' => $mentor->id])->assertNotFound();
        $this->assertDatabaseCount('academy_sessions', 0);
    }

    public function test_calendar_and_session_versions_refuse_lost_updates_and_duplicate_links(): void
    {
        $s = $this->sessionScenario();
        $this->postJson($s['path'], [...$s['body'], 'activity_version' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'ACADEMY_SCHEDULE_STALE');
        $saved = $this->postJson($s['path'], $s['body'])->assertCreated();
        $this->postJson($s['path'], $s['body'])->assertConflict()->assertJsonPath('code', 'ACADEMY_SESSION_ALREADY_LINKED');
        $this->patchJson($s['path'].'/'.$saved->json('data.id'), ['version' => (string) Str::uuid(), 'activity_version' => $s['activity']->version, 'title' => 'Écrasement', 'change_reason' => 'Correction explicite du titre.'])
            ->assertConflict()->assertJsonPath('code', 'ACADEMY_SESSION_VERSION_STALE');
        $this->assertDatabaseCount('academy_sessions', 1);
        $this->assertDatabaseCount('academy_session_revisions', 1);
    }

    public function test_student_cannot_be_responsible_and_suspended_mentor_is_refused(): void
    {
        $s = $this->sessionScenario();
        $this->postJson($s['path'], [...$s['body'], 'responsible_enrollment_id' => $s['student']->id])->assertConflict();
        OrganizationMembership::query()->where('organization_id', $s['org']->id)->where('user_id', $s['mentor_user']->id)->update(['status' => 'suspended']);
        $this->postJson($s['path'], $s['body'])->assertConflict();
        $this->assertDatabaseCount('academy_sessions', 0);
    }

    public function test_session_period_must_fit_cycle_and_active_mentor_enrollment(): void
    {
        $s = $this->sessionScenario();
        $s['activity']->forceFill(['starts_at' => '2027-01-10 10:00:00', 'ends_at' => '2027-01-10 11:00:00'])->save();
        $this->postJson($s['path'], $s['body'])->assertConflict()->assertJsonPath('code', 'ACADEMY_ENROLLMENT_UNAVAILABLE');
        $this->assertDatabaseCount('academy_sessions', 0);
    }

    public function test_attendance_is_versioned_unique_and_corrections_require_a_reason(): void
    {
        $s = $this->sessionScenario();
        $saved = $this->postJson($s['path'], $s['body'])->assertCreated();
        $path = $s['path'].'/'.$saved->json('data.id').'/attendances';
        $body = ['enrollment_id' => $s['student']->id, 'status' => 'present'];
        $attendance = $this->postJson($path, $body)->assertCreated()->assertJsonPath('data.status', 'present');
        $this->postJson($path, $body)->assertConflict()->assertJsonPath('code', 'ACADEMY_ATTENDANCE_ALREADY_EXISTS');
        $id = $attendance->json('data.id');
        $this->patchJson($path.'/'.$id, ['version' => $attendance->json('data.version'), 'status' => 'absent'])->assertUnprocessable()->assertJsonValidationErrors('change_reason');
        $this->patchJson($path.'/'.$id, ['version' => (string) Str::uuid(), 'status' => 'absent', 'change_reason' => 'Correction après vérification.'])
            ->assertConflict()->assertJsonPath('code', 'ACADEMY_ATTENDANCE_VERSION_STALE');
        $this->patchJson($path.'/'.$id, ['version' => $attendance->json('data.version'), 'status' => 'late', 'late_minutes' => 15, 'change_reason' => 'Correction après vérification.'])
            ->assertOk()->assertJsonPath('data.late_minutes', 15)->assertJsonMissingPath('data.change_reason');
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('academy_attendances', 1);
        $this->assertDatabaseCount('academy_session_revisions', 3);
        $this->assertStringNotContainsString('Correction après', DB::table('academy_attendances')->value('change_reason'));
    }

    public function test_changed_schedule_blocks_attendance_until_explicit_same_period_confirmation(): void
    {
        $s = $this->sessionScenario();
        $saved = $this->postJson($s['path'], $s['body'])->assertCreated();
        $path = $s['path'].'/'.$saved->json('data.id');
        $s['activity']->forceFill(['version' => (string) Str::uuid(), 'title' => 'Libellé modifié'])->save();
        $this->getJson($s['path'])->assertOk()->assertJsonPath('data.0.schedule_state', 'changed');
        $this->postJson($path.'/attendances', ['enrollment_id' => $s['student']->id, 'status' => 'present'])->assertConflict()->assertJsonPath('code', 'ACADEMY_SCHEDULE_STALE');
        $this->patchJson($path, ['version' => $saved->json('data.version'), 'activity_version' => $s['activity']->version, 'change_reason' => 'Horaires revérifiés explicitement.'])->assertOk();
        $this->postJson($path.'/attendances', ['enrollment_id' => $s['student']->id, 'status' => 'present'])->assertCreated();
    }

    public function test_calendar_became_private_hides_its_reference_and_times(): void
    {
        $s = $this->sessionScenario();
        $this->postJson($s['path'], $s['body'])->assertCreated();
        $s['activity']->forceFill(['visibility' => 'private', 'sharing_authorized_by' => null, 'sharing_authorized_at' => null, 'title' => 'SECRET_CURRENT_TITLE'])->save();
        $this->getJson($s['path'])->assertOk()->assertJsonPath('data.0.activity', null)->assertJsonPath('data.0.starts_at', null)
            ->assertJsonPath('data.0.schedule_state', 'unavailable')->assertJsonMissing(['label' => 'SECRET_CURRENT_TITLE']);
    }

    public function test_attendance_freezes_period_and_responsible_but_retains_audited_content_edits(): void
    {
        $s = $this->sessionScenario();
        $saved = $this->postJson($s['path'], $s['body'])->assertCreated();
        $path = $s['path'].'/'.$saved->json('data.id');
        $this->postJson($path.'/attendances', ['enrollment_id' => $s['student']->id, 'status' => 'present'])->assertCreated();
        $s['activity']->forceFill(['starts_at' => '2026-10-22 13:00:00', 'ends_at' => '2026-10-22 14:00:00', 'version' => (string) Str::uuid()])->save();
        $this->patchJson($path, ['version' => $saved->json('data.version'), 'activity_version' => $s['activity']->version, 'change_reason' => 'Changement des horaires proposé.'])
            ->assertConflict()->assertJsonPath('code', 'ACADEMY_SESSION_ATTENDANCE_FROZEN');
        $this->assertDatabaseCount('academy_session_revisions', 2);
        $this->getJson($path.'/history?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonMissingPath('data.0.snapshot');
    }

    public function test_future_attendance_and_premature_completion_are_refused(): void
    {
        $s = $this->sessionScenario();
        $s['activity']->forceFill(['starts_at' => '2026-10-22 13:00:00', 'ends_at' => '2026-10-22 14:00:00'])->save();
        $saved = $this->postJson($s['path'], $s['body'])->assertCreated();
        $path = $s['path'].'/'.$saved->json('data.id');
        $this->postJson($path.'/attendances', ['enrollment_id' => $s['student']->id, 'status' => 'present'])->assertConflict()->assertJsonPath('code', 'ACADEMY_SESSION_NOT_STARTED');
        $this->patchJson($path, ['version' => $saved->json('data.version'), 'activity_version' => $s['activity']->version, 'status' => 'completed', 'change_reason' => 'Clôture proposée avant la séance.'])
            ->assertConflict()->assertJsonPath('code', 'ACADEMY_SESSION_COMPLETION_INVALID');
    }

    public function test_cancellation_is_retained_and_refuses_subsequent_presence_writes(): void
    {
        $s = $this->sessionScenario();
        $saved = $this->postJson($s['path'], $s['body'])->assertCreated();
        $path = $s['path'].'/'.$saved->json('data.id');
        $this->patchJson($path, ['version' => $saved->json('data.version'), 'status' => 'cancelled', 'change_reason' => 'Séance annulée explicitement.'])->assertOk();
        $this->postJson($path.'/attendances', ['enrollment_id' => $s['student']->id, 'status' => 'present'])->assertConflict()->assertJsonPath('code', 'ACADEMY_SESSION_CANCELLED');
        $this->assertDatabaseHas('academy_sessions', ['id' => $saved->json('data.id'), 'status' => 'cancelled']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): array
    {
        return ['extra tenant' => [['organization_id' => 'spoof'], 'organization_id'], 'too many exercises' => [['exercises' => array_fill(0, 21, ['title' => 'Exercice', 'minutes' => 5])], 'exercises'],
            'hidden note' => [['exercises' => [['title' => 'Exercice', 'minutes' => 5, 'internal_note' => 'secret']]], 'exercises.0'],
            'missing exercise duration' => [['exercises' => [['title' => 'Exercice']]], 'exercises.0.minutes']];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidInputs')]
    public function test_closed_payload_and_exercise_limits_leave_no_partial_data(array $changes, string $error): void
    {
        $s = $this->sessionScenario();
        $this->postJson($s['path'], array_replace($s['body'], $changes))->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('academy_sessions', 0);
    }

    #[Group('tenant-isolation')]
    public function test_cycle_and_cross_tenant_attendance_ids_cannot_move_records(): void
    {
        $s = $this->sessionScenario();
        $saved = $this->postJson($s['path'], $s['body'])->assertCreated();
        $foreign = AcademyEnrollment::factory()->create();
        $path = $s['path'].'/'.$saved->json('data.id').'/attendances';
        $this->postJson($path, ['enrollment_id' => $foreign->id, 'status' => 'present'])->assertNotFound();
        $this->assertDatabaseCount('academy_attendances', 0);
    }

    #[Group('tenant-isolation')]
    public function test_multi_tenant_manager_cannot_use_foreign_session_activity_or_attendance_ids(): void
    {
        $s = $this->savedSessionScenario();
        $other = $this->savedSessionScenario();
        $this->grantPeopleAccess($other['org'], $s['actor'], ['academy.read', 'academy.manage', 'academy.attendance.manage', 'calendar.read']);
        $this->actingAsWithMfa($s['actor']);
        $this->getJson($other['path'])->assertOk()->assertJsonPath('data.0.id', $other['saved']['id']);
        $this->getJson($other['path'].'/'.$other['saved']['id'].'/attendances')->assertOk()->assertJsonPath('data.0.id', $other['attendance']['id']);
        $before = $this->sessionSnapshot();
        $auditCount = DB::table('organization_audit_events')->count();
        $path = $s['path'].'/'.$s['saved']['id'];
        $foreignPath = $s['path'].'/'.$other['saved']['id'];

        $this->getJson($foreignPath.'/history')->assertNotFound();
        $this->getJson($foreignPath.'/attendances')->assertNotFound();
        $this->patchJson($foreignPath, ['version' => $other['saved']['version'], 'activity_version' => $other['activity']->version, 'title' => 'Modification interdite', 'change_reason' => 'Correction sous le mauvais tenant.'])->assertNotFound();
        $this->postJson($path.'/attendances', ['enrollment_id' => $other['student']->id, 'status' => 'present'])->assertNotFound();
        $this->patchJson($path.'/attendances/'.$other['attendance']['id'], ['version' => $other['attendance']['version'], 'status' => 'absent', 'change_reason' => 'Correction sous le mauvais tenant.'])->assertNotFound();
        $this->postJson($s['path'], [...$s['body'], 'activity_id' => $other['activity']->id, 'activity_version' => $other['activity']->version])->assertNotFound();

        $this->assertSame($before, $this->sessionSnapshot());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('academy_sessions', 2);
        $this->assertDatabaseCount('academy_attendances', 2);
        $this->assertDatabaseCount('academy_session_revisions', 4);
    }

    #[Group('tenant-isolation')]
    public function test_same_tenant_attendance_session_cycle_and_cohort_ids_remain_bound_to_their_parents(): void
    {
        $s = $this->savedSessionScenario();
        $activity = CalendarActivity::factory()->shared()->create(['organization_id' => $s['org']->id, 'kind' => 'academy',
            'starts_at' => '2026-10-22 10:00:00', 'ends_at' => '2026-10-22 11:00:00']);
        $second = $this->postJson($s['path'], [...$s['body'], 'activity_id' => $activity->id, 'activity_version' => $activity->version])->assertCreated()->json('data');
        $secondAttendance = $this->postJson($s['path'].'/'.$second['id'].'/attendances', ['enrollment_id' => $s['student']->id, 'status' => 'present'])->assertCreated()->json('data');
        $cycle = AcademyCycle::factory()->create(['cohort_id' => $s['cohort']->id]);
        $enrollment = AcademyEnrollment::factory()->create(['cycle_id' => $cycle->id]);
        $cohort = Cohort::factory()->create(['organization_id' => $s['org']->id]);
        $before = $this->sessionSnapshot();
        $auditCount = DB::table('organization_audit_events')->count();
        $path = $s['path'].'/'.$s['saved']['id'];
        $wrongCycle = str_replace($s['cycle']->id, $cycle->id, $s['path']);

        $this->getJson($wrongCycle)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($wrongCycle.'/'.$s['saved']['id'].'/history')->assertNotFound();
        $this->getJson($wrongCycle.'/'.$s['saved']['id'].'/attendances')->assertNotFound();
        $this->getJson(str_replace($s['cohort']->id, $cohort->id, $s['path']))->assertNotFound();
        $this->postJson($path.'/attendances', ['enrollment_id' => $enrollment->id, 'status' => 'present'])->assertNotFound();
        $this->patchJson($path.'/attendances/'.$secondAttendance['id'], ['version' => $secondAttendance['version'], 'status' => 'absent', 'change_reason' => 'Correction sous la mauvaise séance.'])->assertNotFound();
        $this->patchJson($s['path'].'/'.$second['id'].'/attendances/'.$s['attendance']['id'], ['version' => $s['attendance']['version'], 'status' => 'absent', 'change_reason' => 'Correction sous la mauvaise séance.'])->assertNotFound();

        $this->assertSame($before, $this->sessionSnapshot());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('academy_sessions', 2);
        $this->assertDatabaseCount('academy_attendances', 2);
        $this->assertDatabaseCount('academy_session_revisions', 4);
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership', 404])]
    #[TestWith(['role', 403])]
    #[TestWith(['read', 403])]
    public function test_revocation_blocks_warmed_session_and_attendance_routes_without_revoking_beta(string $revocation, int $status): void
    {
        $s = $this->savedSessionScenario();
        $other = $this->savedSessionScenario();
        $this->grantPeopleAccess($other['org'], $s['actor'], ['academy.read', 'academy.manage', 'academy.attendance.manage', 'calendar.read']);
        $this->actingAsWithMfa($s['actor']);
        $path = $s['path'].'/'.$s['saved']['id'];
        foreach ([$s['path'], $path.'/history', $path.'/attendances', $this->activityChoices($s)] as $url) {
            $this->getJson($url)->assertOk();
        }
        $before = $this->sessionSnapshot($s['org']->id);
        $auditCount = DB::table('organization_audit_events')->count();
        $assignment = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['actor']->id)->firstOrFail();
        if ($revocation === 'membership') {
            OrganizationMembership::query()->whereKey($assignment->membership_id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        } elseif ($revocation === 'role') {
            $assignment->forceFill(['status' => 'revoked'])->save();
        } else {
            $assignment->role->forceFill(['permissions' => ['academy.manage', 'academy.attendance.manage', 'calendar.read']])->save();
        }

        foreach ([$s['path'], $path.'/history', $path.'/attendances', $this->activityChoices($s)] as $url) {
            $this->getJson($url)->assertStatus($status)->assertJsonMissingPath('data')->assertDontSee('Compte rendu');
        }
        $this->postJson($s['path'], $s['body'])->assertStatus($status);
        $this->patchJson($path, ['version' => $s['saved']['version'], 'activity_version' => $s['activity']->version, 'title' => 'Modification interdite', 'change_reason' => 'Correction après retrait des droits.'])->assertStatus($status);
        $this->postJson($path.'/attendances', ['enrollment_id' => $s['student']->id, 'status' => 'present'])->assertStatus($status);
        $this->patchJson($path.'/attendances/'.$s['attendance']['id'], ['version' => $s['attendance']['version'], 'status' => 'absent', 'change_reason' => 'Correction après retrait des droits.'])->assertStatus($status);
        $this->assertSame($before, $this->sessionSnapshot($s['org']->id));
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->getJson($other['path'])->assertOk()->assertJsonPath('data.0.id', $other['saved']['id']);
        $this->patchJson($other['path'].'/'.$other['saved']['id'].'/attendances/'.$other['attendance']['id'],
            ['version' => $other['attendance']['version'], 'status' => 'absent', 'change_reason' => 'Correction autorisée dans Beta.'])->assertOk()->assertJsonPath('data.status', 'absent');
        $this->assertSame($before, $this->sessionSnapshot($s['org']->id));
        $this->assertDatabaseCount('academy_session_revisions', 5);
    }

    #[Group('tenant-isolation')]
    public function test_removing_session_management_preserves_attendance_management_and_read_access(): void
    {
        $s = $this->savedSessionScenario();
        $path = $s['path'].'/'.$s['saved']['id'];
        $this->getJson($s['path'])->assertOk();
        $before = $this->sessionSnapshot();
        $auditCount = DB::table('organization_audit_events')->count();
        $this->sessionRole($s)->role->forceFill(['permissions' => ['academy.read', 'academy.attendance.manage', 'calendar.read']])->save();

        $this->getJson($path.'/history')->assertOk();
        $this->getJson($path.'/attendances')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($s['path'], $s['body'])->assertForbidden();
        $this->patchJson($path, ['version' => $s['saved']['version'], 'activity_version' => $s['activity']->version, 'title' => 'Modification interdite', 'change_reason' => 'Correction après retrait des droits.'])->assertForbidden();
        $this->assertSame($before, $this->sessionSnapshot());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->patchJson($path.'/attendances/'.$s['attendance']['id'], ['version' => $s['attendance']['version'], 'status' => 'absent', 'change_reason' => 'Correction des présences autorisée.'])->assertOk()->assertJsonPath('data.status', 'absent');
        $this->assertDatabaseCount('academy_sessions', 1);
        $this->assertDatabaseCount('academy_attendances', 1);
        $this->assertDatabaseCount('academy_session_revisions', 3);
    }

    #[Group('tenant-isolation')]
    public function test_removing_attendance_management_preserves_session_edits_without_changing_presences(): void
    {
        $s = $this->savedSessionScenario();
        $path = $s['path'].'/'.$s['saved']['id'];
        $this->getJson($path.'/attendances')->assertOk();
        $before = $this->sessionSnapshot();
        $auditCount = DB::table('organization_audit_events')->count();
        $this->sessionRole($s)->role->forceFill(['permissions' => ['academy.read', 'academy.manage', 'calendar.read']])->save();

        $this->getJson($path.'/attendances')->assertOk()->assertJsonPath('data.0.status', 'present');
        $this->postJson($path.'/attendances', ['enrollment_id' => $s['student']->id, 'status' => 'present'])->assertForbidden();
        $this->patchJson($path.'/attendances/'.$s['attendance']['id'], ['version' => $s['attendance']['version'], 'status' => 'absent', 'change_reason' => 'Correction interdite des présences.'])->assertForbidden();
        $this->assertSame($before, $this->sessionSnapshot());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->patchJson($path, ['version' => $s['saved']['version'], 'activity_version' => $s['activity']->version, 'title' => 'Titre corrigé', 'change_reason' => 'Correction du contenu autorisée.'])->assertOk()->assertJsonPath('data.title', 'Titre corrigé');
        $this->getJson($path.'/attendances')->assertOk()->assertJsonPath('data.0.version', $s['attendance']['version'])->assertJsonPath('data.0.status', 'present');
        $this->assertDatabaseCount('academy_session_revisions', 3);
    }

    #[Group('tenant-isolation')]
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_calendar_read_revocation_or_private_activity_hides_schedule_and_blocks_mutations(bool $privateActivity): void
    {
        $s = $this->savedSessionScenario();
        $path = $s['path'].'/'.$s['saved']['id'];
        $this->getJson($s['path'])->assertOk()->assertJsonPath('data.0.schedule_state', 'current');
        $this->getJson($this->activityChoices($s))->assertOk()->assertJsonCount(1, 'data');
        $before = $this->sessionSnapshot();
        $auditCount = DB::table('organization_audit_events')->count();
        if ($privateActivity) {
            $s['activity']->forceFill(['visibility' => 'private', 'sharing_authorized_by' => null, 'sharing_authorized_at' => null,
                'title' => 'SECRET_CURRENT_TITLE', 'version' => (string) Str::uuid()])->save();
        } else {
            $this->sessionRole($s)->role->forceFill(['permissions' => ['academy.read', 'academy.manage', 'academy.attendance.manage']])->save();
        }

        $this->getJson($s['path'])->assertOk()->assertJsonPath('data.0.activity', null)->assertJsonPath('data.0.starts_at', null)
            ->assertJsonPath('data.0.schedule_state', 'unavailable')->assertJsonPath('data.0.report', 'Compte rendu de séance')->assertDontSee('SECRET_CURRENT_TITLE');
        $this->getJson($path.'/history')->assertOk()->assertJsonMissingPath('data.0.snapshot');
        $this->getJson($path.'/attendances')->assertOk()->assertJsonPath('data.0.status', 'present');
        if ($privateActivity) {
            $this->getJson($this->activityChoices($s))->assertOk()->assertJsonCount(0, 'data');
        } else {
            $this->getJson($this->activityChoices($s))->assertForbidden();
        }
        $status = $privateActivity ? 404 : 403;
        $this->patchJson($path, ['version' => $s['saved']['version'], 'activity_version' => $s['activity']->version, 'title' => 'Modification interdite', 'change_reason' => 'Correction interdite sans calendrier.'])->assertStatus($status);
        $this->postJson($path.'/attendances', ['enrollment_id' => $s['student']->id, 'status' => 'present'])->assertStatus($status);
        $this->patchJson($path.'/attendances/'.$s['attendance']['id'], ['version' => $s['attendance']['version'], 'status' => 'absent', 'change_reason' => 'Correction interdite sans calendrier.'])->assertStatus($status);
        $this->assertSame($before, $this->sessionSnapshot());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
    }

    /** @return array<string, mixed> */
    private function savedSessionScenario(): array
    {
        $s = $this->sessionScenario();
        $saved = $this->postJson($s['path'], $s['body'])->assertCreated()->json('data');
        $attendance = $this->postJson($s['path'].'/'.$saved['id'].'/attendances', ['enrollment_id' => $s['student']->id, 'status' => 'present'])->assertCreated()->json('data');

        return [...$s, 'saved' => $saved, 'attendance' => $attendance];
    }

    /** @param array<string, mixed> $s */
    private function sessionRole(array $s): OrganizationRoleAssignment
    {
        return OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['actor']->id)->firstOrFail();
    }

    /** @param array<string, mixed> $s */
    private function activityChoices(array $s): string
    {
        return str_replace('/sessions', '/session-activities', $s['path']).'?from_date=2026-10-01&to_date=2026-11-01';
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function sessionSnapshot(?string $organization = null): array
    {
        $snapshot = [];
        foreach (['academy_sessions', 'academy_attendances', 'academy_session_revisions'] as $table) {
            $snapshot[$table] = array_values(DB::table($table)->when($organization !== null, fn ($query) => $query->where('organization_id', $organization))
                ->orderBy('id')->get()->map(fn (object $row): array => get_object_vars($row))->all());
        }

        return $snapshot;
    }

    public function test_schema_history_and_operator_export_keep_ciphertext_and_refuse_destructive_rollback(): void
    {
        $s = $this->sessionScenario();
        $saved = $this->postJson($s['path'], $s['body'])->assertCreated();
        $this->postJson($s['path'].'/'.$saved->json('data.id').'/attendances', ['enrollment_id' => $s['student']->id, 'status' => 'present'])->assertCreated();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($s['org']->id, 100));
        $sessions = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'academy_sessions'));
        $this->assertCount(1, $sessions);
        $this->assertStringNotContainsString('Consigne technique', $sessions[0]['data']['exercises']);
        try {
            DB::transaction(fn () => DB::table('academy_session_revisions')->delete());
            $this->fail('Retain academy history.');
        } catch (QueryException) {
            $this->assertDatabaseCount('academy_session_revisions', 2);
        }
        $migration = require database_path('migrations/2026_10_22_172532_create_academy_sessions_and_attendances.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
