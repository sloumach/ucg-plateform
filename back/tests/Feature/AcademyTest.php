<?php

namespace Tests\Feature;

use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\Cohort;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class AcademyTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_guests_cannot_read_or_write(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->path($org))->assertUnauthorized();
        $this->postJson($this->path($org), ['name' => 'Pilote'])->assertUnauthorized();
    }

    public function test_owner_has_no_implicit_academy_rights(): void
    {
        $owner = User::factory()->withMfa()->create();
        $org = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->actingAsWithMfa($owner)->getJson($this->path($org))->assertForbidden();
        $this->postJson($this->path($org), ['name' => 'Pilote'])->assertForbidden();
        $this->assertDatabaseCount('academy_cohorts', 0);
    }

    public function test_reader_cannot_manage_or_list_person_choices(): void
    {
        $org = $this->editor(['academy.read']);
        $this->getJson($this->path($org))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->path($org), ['name' => 'Pilote'])->assertForbidden();
        $this->getJson('/api/v1/tenants/'.$org->id.'/academy/people')->assertForbidden();
    }

    public function test_cohort_create_update_and_history_are_private_and_audited(): void
    {
        $org = $this->editor();
        $before = DB::table('organization_role_assignments')->count();
        $response = $this->postJson($this->path($org), ['name' => 'PRIVATE_COHORT'])
            ->assertCreated()->assertJsonPath('notification.message', 'Académie mise à jour.')
            ->assertJsonMissingPath('data.organization_id');
        $id = $response->json('data.id');
        $this->patchJson($this->path($org).'/'.$id, ['version' => $response->json('data.version'), 'name' => 'Nouveau nom'])
            ->assertOk()->assertJsonPath('data.name', 'Nouveau nom');
        $this->getJson($this->path($org).'/'.$id.'/history')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.snapshot');
        $this->assertDatabaseHas('academy_cohorts', ['id' => $id, 'name' => 'Nouveau nom']);
        $this->assertDatabaseCount('academy_revisions', 2);
        $this->assertDatabaseCount('organization_audit_events', 2);
        $this->assertDatabaseCount('organization_role_assignments', $before);
        $this->assertStringNotContainsString('PRIVATE_COHORT', DB::table('academy_revisions')->value('snapshot'));
    }

    public function test_stale_update_and_archived_cohort_refuse_mutations(): void
    {
        $org = $this->editor();
        $cohort = Cohort::factory()->create(['organization_id' => $org->id]);
        $this->patchJson($this->path($org).'/'.$cohort->id, ['version' => (string) Str::uuid(), 'name' => 'Perdu'])
            ->assertConflict()->assertJsonPath('code', 'ACADEMY_VERSION_STALE');
        $this->patchJson($this->path($org).'/'.$cohort->id, ['version' => $cohort->version, 'status' => 'archived'])->assertOk();
        $this->postJson($this->path($org).'/'.$cohort->id.'/cycles', $this->cycleInput())->assertConflict();
        $this->getJson($this->path($org).'/'.$cohort->id.'/history')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_foreign_cohort_cycle_and_person_return_404(): void
    {
        $org = $this->editor();
        $foreign = AcademyCycle::factory()->create();
        $this->getJson($this->path($org).'/'.$foreign->cohort_id.'/cycles')->assertNotFound();
        $this->patchJson($this->path($org).'/'.$foreign->cohort_id, ['version' => (string) Str::uuid(), 'name' => 'Vol'])->assertNotFound();
        $cycle = AcademyCycle::factory()->create(['cohort_id' => Cohort::factory()->create(['organization_id' => $org->id])->id]);
        $person = Person::factory()->create();
        $this->postJson($this->path($org).'/'.$cycle->cohort_id.'/enrollments', $this->enrollmentInput($foreign, $person))->assertNotFound();
        $this->postJson($this->path($org).'/'.$cycle->cohort_id.'/enrollments', $this->enrollmentInput($cycle, $person))->assertNotFound();
        $this->assertDatabaseCount('academy_enrollments', 0);
    }

    public function test_cycle_dates_timezone_and_weekly_schedule_are_saved(): void
    {
        $org = $this->editor();
        $cohort = Cohort::factory()->create(['organization_id' => $org->id]);
        $input = $this->cycleInput();
        $this->postJson($this->path($org).'/'.$cohort->id.'/cycles', $input)
            ->assertCreated()->assertJsonPath('data.timetable.0.day', 1)->assertJsonPath('data.timezone', 'Europe/Paris');
        $this->assertDatabaseHas('academy_cycles', ['cohort_id' => $cohort->id, 'name' => 'Automne']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidCycles(): array
    {
        return ['reversed dates' => [['starts_on' => '2027-01-01'], 'ends_on'],
            'unknown timezone' => [['timezone' => 'Unknown/Zone'], 'timezone'],
            'overnight slot' => [['timetable' => [['day' => 1, 'starts_at' => '23:00', 'ends_at' => '01:00']]], 'timetable'],
            'overlapping slots' => [['timetable' => [['day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00'], ['day' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00']]], 'timetable'],
            'extra nested key' => [['timetable' => [['day' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00', 'secret' => true]]], 'timetable.0'],
            'too many slots' => [['timetable' => array_fill(0, 29, ['day' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00'])], 'timetable'],
            'spoofed tenant' => [['organization_id' => 'spoof'], 'organization_id']];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidCycles')]
    public function test_invalid_cycles_leave_no_partial_data(array $changes, string $error): void
    {
        $org = $this->editor();
        $cohort = Cohort::factory()->create(['organization_id' => $org->id]);
        $this->postJson($this->path($org).'/'.$cohort->id.'/cycles', array_replace($this->cycleInput(), $changes))
            ->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('academy_cycles', 0);
        $this->assertDatabaseCount('academy_revisions', 0);
    }

    public function test_empty_timetable_is_supported_and_no_calendar_activity_is_created(): void
    {
        $org = $this->editor();
        $cohort = Cohort::factory()->create(['organization_id' => $org->id]);
        $this->postJson($this->path($org).'/'.$cohort->id.'/cycles', [...$this->cycleInput(), 'timetable' => []])
            ->assertCreated()->assertJsonPath('data.timetable', []);
        $this->assertDatabaseCount('calendar_activities', 0);
    }

    public function test_student_enrollment_retains_history_and_refuses_overlaps(): void
    {
        $org = $this->editor();
        $cycle = AcademyCycle::factory()->create(['cohort_id' => Cohort::factory()->create(['organization_id' => $org->id])->id]);
        $person = Person::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
        $path = $this->path($org).'/'.$cycle->cohort_id.'/enrollments';
        $input = $this->enrollmentInput($cycle, $person);
        $saved = $this->postJson($path, $input)->assertCreated();
        $this->postJson($path, $input)->assertConflict()->assertJsonPath('code', 'ACADEMY_ENROLLMENT_OVERLAP');
        $this->patchJson($path.'/'.$saved->json('data.id'), ['version' => $saved->json('data.version'), 'status' => 'withdrawn'])->assertOk();
        $this->postJson($path, $input)->assertCreated();
        $this->assertDatabaseCount('academy_enrollments', 2);
        $this->assertDatabaseCount('academy_revisions', 3);
    }

    public function test_mentor_requires_a_verified_effective_member_without_granting_permissions(): void
    {
        $org = $this->editor();
        $cycle = AcademyCycle::factory()->create(['cohort_id' => Cohort::factory()->create(['organization_id' => $org->id])->id]);
        $mentor = User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $org->id, 'user_id' => $mentor->id]);
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $mentor->id, 'status' => 'active']);
        $this->postJson($this->path($org).'/'.$cycle->cohort_id.'/enrollments', [...$this->enrollmentInput($cycle, $person), 'role' => 'mentor'])->assertCreated();
        $this->assertDatabaseMissing('organization_role_assignments', ['user_id' => $mentor->id]);
        $unlinked = Person::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
        $this->postJson($this->path($org).'/'.$cycle->cohort_id.'/enrollments', [...$this->enrollmentInput($cycle, $unlinked), 'role' => 'mentor'])
            ->assertConflict()->assertJsonPath('code', 'ACADEMY_PERSON_UNAVAILABLE');
    }

    public function test_suspended_member_cannot_be_enrolled_as_mentor(): void
    {
        $org = $this->editor();
        $cycle = AcademyCycle::factory()->create(['cohort_id' => Cohort::factory()->create(['organization_id' => $org->id])->id]);
        $user = User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'status' => 'suspended']);
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
        $this->postJson($this->path($org).'/'.$cycle->cohort_id.'/enrollments', [...$this->enrollmentInput($cycle, $person), 'role' => 'mentor'])->assertConflict();
        $this->assertDatabaseCount('academy_enrollments', 0);
    }

    public function test_enrollment_dates_are_bounded_by_cycle_and_cycle_dates_freeze_after_enrollment(): void
    {
        $org = $this->editor();
        $cycle = AcademyCycle::factory()->create(['cohort_id' => Cohort::factory()->create(['organization_id' => $org->id])->id]);
        $person = Person::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
        $path = $this->path($org).'/'.$cycle->cohort_id;
        $input = $this->enrollmentInput($cycle, $person);
        $this->postJson($path.'/enrollments', [...$input, 'ends_on' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->postJson($path.'/enrollments', $input)->assertCreated();
        $this->patchJson($path.'/cycles/'.$cycle->id, ['version' => $cycle->version, 'starts_on' => '2026-09-01'])
            ->assertConflict()->assertJsonPath('code', 'ACADEMY_CYCLE_DATES_LOCKED');
    }

    public function test_lists_are_paginated_and_reject_unknown_filters(): void
    {
        $org = $this->editor();
        Cohort::factory()->count(3)->create(['organization_id' => $org->id]);
        Cohort::factory()->create();
        $this->getJson($this->path($org).'?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson($this->path($org).'?per_page=101')->assertUnprocessable();
        $this->getJson($this->path($org).'?organization_id=spoof')->assertUnprocessable();
    }

    public function test_person_choices_expose_only_active_tenant_labels(): void
    {
        $org = $this->editor();
        Person::factory()->create(['organization_id' => $org->id, 'pseudonym' => 'Camille', 'status' => 'active']);
        Person::factory()->create(['organization_id' => $org->id, 'status' => 'former']);
        Person::factory()->create();
        $this->getJson('/api/v1/tenants/'.$org->id.'/academy/people')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'Camille')->assertJsonMissingPath('data.0.user_id')->assertJsonMissingPath('data.0.contact_email');
    }

    public function test_database_blocks_cross_tenant_cycle_and_history_deletion(): void
    {
        $org = $this->editor();
        $foreign = Cohort::factory()->create();
        try {
            DB::transaction(fn () => AcademyCycle::factory()->create(['organization_id' => $org->id, 'cohort_id' => $foreign->id]));
            $this->fail('Cross tenant cycle must be refused by the database.');
        } catch (QueryException) {
            $this->assertDatabaseCount('academy_cycles', 0);
        }
        $this->postJson($this->path($org), ['name' => 'Conservé'])->assertCreated();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('academy_revisions')->delete());
    }

    /** @return array<string, array{list<string>, bool, bool}> */
    public static function permissionCases(): array
    {
        return ['none' => [[], false, false], 'read only' => [['academy.read'], true, false],
            'manage alone' => [['academy.manage'], false, false], 'both' => [['academy.read', 'academy.manage'], true, true]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissionCases')]
    public function test_policy_requires_explicit_read_and_manage_rights(array $permissions, bool $read, bool $manage): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $gate = app(Gate::class)->forUser($user);
        $this->assertSame($read, $gate->allows('viewAny', [Cohort::class, $context]));
        $this->assertSame($manage, $gate->allows('manage', [Cohort::class, $context]));
    }

    public function test_enrollment_labels_are_resolved_in_one_query_for_the_whole_page(): void
    {
        $org = $this->editor();
        $cycle = AcademyCycle::factory()->create(['cohort_id' => Cohort::factory()->create(['organization_id' => $org->id])->id]);
        $path = $this->path($org).'/'.$cycle->cohort_id.'/enrollments';
        foreach (['Camille', 'Alex'] as $label) {
            $person = Person::factory()->create(['organization_id' => $org->id, 'pseudonym' => $label, 'status' => 'active']);
            $this->postJson($path, $this->enrollmentInput($cycle, $person))->assertCreated();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $response = $this->getJson($path)->assertOk()->assertJsonCount(2, 'data');
            $queries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "people"'));
            $this->assertCount(1, $queries);
            $this->assertEqualsCanonicalizing(['Camille', 'Alex'], array_column($response->json('data'), 'person_label'));
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_cycle_id_from_another_cohort_is_refused_without_partial_history(): void
    {
        $org = $this->editor();
        $one = AcademyCycle::factory()->create(['cohort_id' => Cohort::factory()->create(['organization_id' => $org->id])->id]);
        $two = AcademyCycle::factory()->create(['cohort_id' => Cohort::factory()->create(['organization_id' => $org->id])->id]);
        $person = Person::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
        $this->postJson($this->path($org).'/'.$one->cohort_id.'/enrollments', $this->enrollmentInput($two, $person))->assertNotFound();
        $this->assertDatabaseCount('academy_enrollments', 0);
        $this->assertDatabaseCount('academy_revisions', 0);
    }

    public function test_empty_schema_can_rollback_but_populated_history_cannot(): void
    {
        $coaching = require database_path('migrations/2026_10_22_224821_create_academy_objectives_and_feedback.php');
        $coaching->down();
        $sessions = require database_path('migrations/2026_10_22_172532_create_academy_sessions_and_attendances.php');
        $sessions->down();
        $migration = require database_path('migrations/2026_10_22_100213_create_academy_cohorts_cycles_and_enrollments.php');
        $migration->down();
        $migration->up();
        $sessions->up();
        $coaching->up();
        $org = $this->editor();
        $this->postJson($this->path($org), ['name' => 'Conservé'])->assertCreated();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ACA-001 rollback cannot discard history.');
        $migration->down();
    }

    /** @param list<string> $permissions */
    private function editor(array $permissions = ['academy.read', 'academy.manage']): Organization
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return $org;
    }

    private function path(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/academy/cohorts';
    }

    /** @return array<string, mixed> */
    private function cycleInput(): array
    {
        return ['name' => 'Automne', 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31', 'timezone' => 'Europe/Paris',
            'timetable' => [['day' => 1, 'starts_at' => '18:00', 'ends_at' => '20:00']]];
    }

    /** @return array<string, mixed> */
    private function enrollmentInput(AcademyCycle $cycle, Person $person): array
    {
        return ['cycle_id' => $cycle->id, 'person_id' => $person->id, 'role' => 'student', 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31'];
    }
}
