<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarConflictOverride;
use App\Modules\Scheduling\Domain\Models\CalendarParticipant;
use App\Modules\Scheduling\Domain\Models\CalendarReservation;
use App\Modules\Scheduling\Domain\Models\CalendarResource;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CalendarConflictControllerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesPeopleAccess;

    public function test_preview_is_401_for_guest_and_403_for_reader(): void
    {
        $org = Organization::factory()->create();
        $this->postJson($this->root($org).'/activity-conflicts', $this->input())->assertUnauthorized();
        $this->member($org, ['calendar.read']);
        $this->postJson($this->root($org).'/activity-conflicts', $this->input())->assertForbidden();
    }

    public function test_preview_reports_private_person_booking_before_confirmation_without_writes_or_private_content(): void
    {
        $org = $this->editor();
        Mail::fake();
        Http::preventStrayRequests();
        $person = Person::factory()->create(['organization_id' => $org->id]);
        $other = $this->other($org, ['participant_ids' => [$person->id]]);
        $body = [...$this->input(), 'participant_ids' => [$person->id]];
        $this->postJson($this->root($org).'/activity-conflicts', $body)->assertOk()->assertJsonCount(1, 'data.conflicts')
            ->assertJsonPath('data.conflicts.0.id', $other->id)->assertJsonPath('data.conflicts.0.causes', ['person'])
            ->assertJsonMissingPath('data.conflicts.0.description')->assertJsonMissingPath('data.conflicts.0.url')
            ->assertJsonMissingPath('data.booking')->assertJsonPath('data.can_override', false)
            ->assertJsonPath('data.availability_checked', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('calendar_activities', 1);
        $this->assertDatabaseCount('calendar_conflict_overrides', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
        $this->postJson($this->root($org).'/activities', $body)->assertConflict()->assertJsonPath('code', 'CALENDAR_CONFLICT_REVIEW_REQUIRED');
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_team_and_resource_collisions_are_detected_without_implicit_roster_members(): void
    {
        $org = $this->editor();
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $resource = CalendarResource::factory()->create(['organization_id' => $org->id]);
        $other = $this->other($org, ['team_id' => $team->id, 'resource_ids' => [$resource->id]]);
        $body = [...$this->input(), 'team_id' => $team->id, 'resource_ids' => [$resource->id]];
        $this->postJson($this->root($org).'/activity-conflicts', $body)->assertOk()->assertJsonPath('data.conflicts.0.causes', ['team', 'resource'])
            ->assertJsonPath('data.conflicts.0.resource_ids', [$resource->id])->assertJsonPath('data.conflicts.0.person_ids', []);
    }

    public function test_exception_needs_current_review_dedicated_permission_reason_and_confirmation(): void
    {
        $org = $this->editor();
        $person = Person::factory()->create(['organization_id' => $org->id]);
        $this->other($org, ['participant_ids' => [$person->id]]);
        $body = [...$this->input(), 'participant_ids' => [$person->id]];
        $token = $this->preview($org, $body);
        $this->postJson($this->root($org).'/activities', [...$body, 'conflict_review' => $token, 'conflict_reason' => 'Décision explicite', 'confirm_conflicts' => true])->assertForbidden();
        $this->member($org, ['calendar.read', 'calendar.write', 'calendar.conflicts.override', 'availability.read']);
        $token = $this->preview($org, $body);
        $this->postJson($this->root($org).'/activities', [...$body, 'conflict_review' => $token])->assertUnprocessable()->assertJsonValidationErrors('conflict_reason');
        $this->postJson($this->root($org).'/activities', [...$body, 'conflict_review' => $token, 'conflict_reason' => 'court', 'confirm_conflicts' => true])->assertUnprocessable();
        $data = $this->postJson($this->root($org).'/activities', [...$body, 'conflict_review' => $token, 'conflict_reason' => 'SECRET_REASON validée', 'confirm_conflicts' => true])->assertCreated()->json('data');
        $this->assertDatabaseCount('calendar_conflict_overrides', 1);
        $entry = CalendarConflictOverride::query()->firstOrFail();
        $this->assertSame($data['version'], $entry->activity_version);
        $this->assertStringNotContainsString('SECRET_REASON', DB::table('calendar_conflict_overrides')->value('reason'));
        $this->assertStringNotContainsString('SECRET_PRIVATE_TITLE', DB::table('calendar_conflict_overrides')->value('snapshot'));
        $this->assertStringNotContainsString('SECRET_REASON', json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR));
        $this->getJson($this->root($org).'/activities/'.$data['id'].'/conflict-overrides?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reason', 'SECRET_REASON validée')->assertJsonMissingPath('data.0.actor_user_id')->assertJsonMissingPath('data.0.booking');
        $this->member($org, ['calendar.read', 'calendar.write']);
        $this->getJson($this->root($org).'/activities/'.$data['id'].'/conflict-overrides')->assertForbidden();
    }

    public function test_clear_review_is_invalidated_by_concurrent_conflict_and_by_candidate_changes(): void
    {
        $org = $this->editor();
        $person = Person::factory()->create(['organization_id' => $org->id]);
        $body = [...$this->input(), 'participant_ids' => [$person->id]];
        $token = $this->preview($org, $body);
        $this->other($org, ['participant_ids' => [$person->id]]);
        $this->postJson($this->root($org).'/activities', [...$body, 'conflict_review' => $token])->assertConflict()->assertJsonPath('code', 'CALENDAR_CONFLICTS_CHANGED');
        $this->postJson($this->root($org).'/activities', [...$body, 'participant_ids' => [], 'conflict_review' => $token])->assertConflict();
        $this->assertDatabaseCount('calendar_activities', 1);
    }

    public function test_review_expires_and_cannot_be_forged_or_reused_by_another_actor(): void
    {
        $this->travelTo('2026-10-17 12:00:00');
        $org = $this->editor();
        $body = $this->input();
        $token = $this->preview($org, $body);
        $this->postJson($this->root($org).'/activities', [...$body, 'conflict_review' => 'forged'])->assertConflict();
        $this->travel(5)->minutes();
        $this->postJson($this->root($org).'/activities', [...$body, 'conflict_review' => $token])->assertConflict();
        $token = $this->preview($org, $body);
        $this->member($org, ['calendar.read', 'calendar.write']);
        $this->postJson($this->root($org).'/activities', [...$body, 'conflict_review' => $token])->assertConflict();
        $this->assertDatabaseCount('calendar_activities', 0);
    }

    public function test_availability_check_requires_its_own_read_permission_and_never_exposes_comments(): void
    {
        $org = $this->editor();
        $person = Person::factory()->create(['organization_id' => $org->id]);
        foreach (['unavailable', 'uncertain', 'available'] as $status) {
            Availability::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id, 'status' => $status, 'comment' => 'SECRET_MEDICAL']);
        }
        $body = [...$this->input(), 'participant_ids' => [$person->id]];
        $this->postJson($this->root($org).'/activity-conflicts', $body)->assertJsonPath('data.availability_checked', false)->assertJsonCount(0, 'data.conflicts');
        $this->member($org, ['calendar.read', 'calendar.write', 'availability.read']);
        $reply = $this->postJson($this->root($org).'/activity-conflicts', $body)->assertOk()->assertJsonPath('data.availability_checked', true)->assertJsonCount(2, 'data.conflicts');
        $content = json_encode($reply->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SECRET_MEDICAL', $content);
        $this->assertStringNotContainsString('comment', $content);
    }

    public function test_half_open_adjacency_archive_and_foreign_tenant_are_not_conflicts(): void
    {
        $org = $this->editor();
        $person = Person::factory()->create(['organization_id' => $org->id]);
        $this->other($org, ['participant_ids' => [$person->id], 'starts_at' => '2026-10-18 07:00:00', 'ends_at' => '2026-10-18 08:00:00']);
        $this->other($org, ['participant_ids' => [$person->id], 'starts_at' => '2026-10-18 09:00:00', 'ends_at' => '2026-10-18 10:00:00']);
        $this->other($org, ['participant_ids' => [$person->id], 'archived_at' => now()]);
        $this->other(Organization::factory()->create());
        $this->postJson($this->root($org).'/activity-conflicts', [...$this->input(), 'participant_ids' => [$person->id]])->assertOk()->assertJsonCount(0, 'data.conflicts');
    }

    public function test_edit_excludes_itself_checks_effective_references_and_archive_releases_resource(): void
    {
        $org = $this->editor();
        $resource = CalendarResource::factory()->create(['organization_id' => $org->id]);
        $data = $this->postJson($this->root($org).'/activities', [...$this->input(), 'resource_ids' => [$resource->id]])->assertCreated()->assertJsonPath('data.resources.0.label', 'Salle de travail')->json('data');
        $this->postJson($this->root($org).'/activity-conflicts', ['activity_id' => strtoupper($data['id']), 'version' => $data['version'], 'title' => 'Renommée'])->assertOk()->assertJsonCount(0, 'data.conflicts');
        $this->patchJson($this->root($org).'/activities/'.$data['id'], ['version' => $data['version'], 'archive' => true])->assertOk();
        $this->assertDatabaseCount('calendar_reservations', 1);
        $this->postJson($this->root($org).'/activities', [...$this->input(), 'resource_ids' => [$resource->id]])->assertCreated();
    }

    public function test_foreign_or_archived_resources_and_foreign_activity_ids_are_404(): void
    {
        $org = $this->editor();
        $foreign = CalendarResource::factory()->create();
        $archived = CalendarResource::factory()->create(['organization_id' => $org->id, 'status' => 'archived']);
        foreach ([$foreign->id, $archived->id] as $id) {
            $this->postJson($this->root($org).'/activity-conflicts', [...$this->input(), 'resource_ids' => [$id]])->assertNotFound();
            $this->postJson($this->root($org).'/activities', [...$this->input(), 'resource_ids' => [$id]])->assertNotFound();
        }
        $other = CalendarActivity::factory()->create();
        $this->postJson($this->root($org).'/activity-conflicts', ['activity_id' => $other->id, 'version' => $other->version, 'title' => 'No'])->assertNotFound();
        $this->assertDatabaseCount('calendar_reservations', 0);
    }

    public function test_more_than_one_hundred_conflicts_blocks_even_an_exception(): void
    {
        $org = $this->editor(['calendar.read', 'calendar.write', 'calendar.conflicts.override']);
        $team = Team::factory()->create(['organization_id' => $org->id]);
        CalendarActivity::factory()->count(101)->create(['organization_id' => $org->id, 'team_id' => $team->id, 'starts_at' => '2026-10-18 08:00:00', 'ends_at' => '2026-10-18 09:00:00']);
        $body = [...$this->input(), 'team_id' => $team->id];
        $report = $this->postJson($this->root($org).'/activity-conflicts', $body)->assertOk()->assertJsonPath('data.truncated', true)->assertJsonCount(100, 'data.conflicts')->json('data');
        $this->postJson($this->root($org).'/activities', [...$body, 'conflict_review' => $report['review_token'], 'conflict_reason' => 'Exception impossible', 'confirm_conflicts' => true])->assertConflict()->assertJsonPath('code', 'CALENDAR_CONFLICT_LIMIT');
        $this->assertDatabaseCount('calendar_conflict_overrides', 0);
    }

    public function test_audit_failure_rolls_back_activity_reservations_and_exception(): void
    {
        $org = $this->editor(['calendar.read', 'calendar.write', 'calendar.conflicts.override']);
        $resource = CalendarResource::factory()->create(['organization_id' => $org->id]);
        $this->other($org, ['resource_ids' => [$resource->id]]);
        $body = [...$this->input(), 'resource_ids' => [$resource->id]];
        $token = $this->preview($org, $body);
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Unavailable'));
        $this->postJson($this->root($org).'/activities', [...$body, 'conflict_review' => $token, 'conflict_reason' => 'Décision autorisée', 'confirm_conflicts' => true])->assertStatus(500);
        $this->assertDatabaseCount('calendar_activities', 1);
        $this->assertDatabaseCount('calendar_reservations', 1);
        $this->assertDatabaseCount('calendar_conflict_overrides', 0);
    }

    public function test_payload_rejects_foreign_fields_malformed_references_and_stale_versions(): void
    {
        $org = $this->editor();
        foreach (['organization_id', 'conflict_reason', 'confirm_conflicts', 'resource_ids.*'] as $field) {
            $this->postJson($this->root($org).'/activity-conflicts', [...$this->input(), $field => 'forged'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $id = (string) Str::uuid();
        $this->postJson($this->root($org).'/activities', [...$this->input(), 'resource_ids' => [$id, strtoupper($id)]])->assertUnprocessable()
            ->assertJsonFragment(['resource_ids.0' => ['Une ressource ne peut être réservée qu’une seule fois par activité.']]);
        $this->postJson($this->root($org).'/activities', [...$this->input(), 'resource_ids' => array_fill(0, 21, $id)])->assertUnprocessable()
            ->assertJsonPath('errors.resource_ids.0', 'Choisissez une liste de 20 ressources distinctes au maximum.');
        $this->postJson($this->root($org).'/activity-conflicts', [...$this->input(), 'resource_ids' => 'not-a-list'])->assertUnprocessable()
            ->assertJsonPath('errors.resource_ids.0', 'Choisissez une liste de 20 ressources distinctes au maximum.');
        $this->postJson($this->root($org).'/activity-conflicts', ['activity_id' => $id, 'title' => 'No version'])->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    /** @param list<string> $permissions */
    private function editor(array $permissions = ['calendar.read', 'calendar.write']): Organization
    {
        $org = Organization::factory()->create();
        $this->member($org, $permissions);

        return $org;
    }

    /** @param list<string> $permissions */
    private function member(Organization $org, array $permissions): User
    {
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return $user;
    }

    private function root(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/calendar';
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        return ['title' => 'Activité candidate', 'kind' => 'training', 'starts_at' => '2026-10-18T10:00:00+02:00', 'ends_at' => '2026-10-18T11:00:00+02:00', 'origin_timezone' => 'Europe/Paris'];
    }

    /** @param array<string,mixed> $body */
    private function preview(Organization $org, array $body): string
    {
        return $this->postJson($this->root($org).'/activity-conflicts', $body)->assertOk()->json('data.review_token');
    }

    /** @param array<string,mixed> $changes */
    private function other(Organization $org, array $changes = []): CalendarActivity
    {
        $people = $changes['participant_ids'] ?? [];
        $resources = $changes['resource_ids'] ?? [];
        unset($changes['participant_ids'],$changes['resource_ids']);
        $item = CalendarActivity::factory()->create(['organization_id' => $org->id, 'title' => 'SECRET_PRIVATE_TITLE', 'description' => 'SECRET_DESCRIPTION',
            'starts_at' => '2026-10-18 08:00:00', 'ends_at' => '2026-10-18 09:00:00', ...array_diff_key($changes, ['archived_at' => true])]);
        foreach ($people as $id) {
            CalendarParticipant::factory()->create(['organization_id' => $org->id, 'activity_id' => $item->id, 'person_id' => $id]);
        }
        foreach ($resources as $id) {
            CalendarReservation::factory()->create(['organization_id' => $org->id, 'activity_id' => $item->id, 'resource_id' => $id]);
        }
        if (isset($changes['archived_at'])) {
            $item->forceFill(['archived_at' => $changes['archived_at']])->save();
        }

        return $item;
    }
}
