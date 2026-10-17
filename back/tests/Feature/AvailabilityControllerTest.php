<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Application\Services\AvailabilityService;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class AvailabilityControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_guest_is_401_and_unrelated_calendar_permissions_are_403(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->path($org).$this->window())->assertUnauthorized();
        $this->member($org, ['calendar.read', 'calendar.write']);
        $this->getJson($this->path($org).$this->window())->assertForbidden();
        $this->postJson($this->path($org), $this->input())->assertForbidden();
    }

    public function test_self_creation_encrypts_comment_and_records_utc_snapshot_without_notifications(): void
    {
        [$org,$user,$person] = $this->selfEditor();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        $data = $this->postJson($this->path($org), [...$this->input(), 'comment' => 'PRIVATE_COMMENT'])->assertCreated()
            ->assertJsonPath('data.person_id', $person->id)->assertJsonPath('data.person_label', 'Camille')
            ->assertJsonPath('data.starts_at', '2026-10-18T08:00:00+00:00')->assertJsonPath('data.starts_at_origin', '2026-10-18T10:00:00+02:00')
            ->assertJsonPath('data.comment', 'PRIVATE_COMMENT')->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('notification.message', 'Le créneau de disponibilité a été enregistré.')
            ->assertJsonMissingPath('data.created_by')->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertDatabaseCount('availabilities', 1);
        $this->assertDatabaseCount('availability_revisions', 1);
        $this->assertDatabaseCount('organization_audit_events', 1);
        foreach (['availabilities', 'availability_revisions'] as $table) {
            $this->assertStringNotContainsString('PRIVATE_COMMENT', DB::table($table)->value('comment'));
        }
        $this->assertStringNotContainsString('PRIVATE_COMMENT', json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR));
        $this->getJson($this->path($org, $data['id']).'/history')->assertOk()->assertJsonPath('data.0.comment', 'PRIVATE_COMMENT')
            ->assertJsonPath('data.0.number', 1)->assertJsonMissingPath('data.0.actor_user_id');
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_self_scope_filters_before_pagination_and_returns_404_for_other_people(): void
    {
        [$org,$user,$person] = $this->selfEditor();
        $mine = Availability::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        $other = Availability::factory()->create(['organization_id' => $org->id]);
        $this->getJson($this->path($org).$this->window().'&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $mine->id)->assertJsonPath('availability.viewer_timezone', 'Asia/Tokyo');
        $this->getJson($this->path($org, $other->id))->assertNotFound();
        $this->getJson($this->path($org, $other->id).'/history')->assertNotFound();
        $this->patchJson($this->path($org, $other->id), ['version' => $other->version, 'status' => 'unavailable'])->assertNotFound();
        $this->postJson($this->path($org), [...$this->input(), 'person_id' => $other->person_id])->assertForbidden();
        $this->getJson($this->path($org).$this->window().'&collective=1')->assertForbidden();
        $this->getJson($this->root($org).'/availability-people')->assertForbidden();
        $this->getJson($this->path($org).$this->window().'&person_id='.$other->person_id)->assertUnprocessable();
        $this->assertDatabaseCount('availability_revisions', 0);
    }

    public function test_collective_reader_sees_current_status_without_comments_history_or_writes(): void
    {
        [$org] = $this->selfEditor();
        $data = $this->postJson($this->path($org), [...$this->input(), 'comment' => 'Hidden'])->assertCreated()->json('data');
        $this->member($org, ['availability.read']);
        $this->getJson($this->path($org).$this->window().'&collective=1')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.comment')->assertJsonPath('data.0.can_edit', false)->assertJsonPath('data.0.can_history', false);
        $this->getJson($this->path($org, $data['id']))->assertOk()->assertJsonMissingPath('data.comment');
        $this->getJson($this->path($org, $data['id']).'/history')->assertForbidden();
        $this->patchJson($this->path($org, $data['id']), ['version' => $data['version'], 'status' => 'uncertain'])->assertForbidden();
        $this->getJson($this->path($org).$this->window())->assertForbidden();
        $this->getJson($this->root($org).'/availability-people?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.user_id');
    }

    public function test_manager_can_manage_unlinked_people_and_history_is_paginated_with_prior_values(): void
    {
        $org = Organization::factory()->create();
        $this->member($org, ['availability.read', 'availability.manage']);
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => null]);
        $data = $this->postJson($this->path($org), [...$this->input(), 'person_id' => $person->id, 'comment' => 'Original'])->assertCreated()->json('data');
        $next = $this->patchJson($this->path($org, $data['id']), ['version' => $data['version'], 'status' => 'uncertain', 'comment' => 'Revised'])->assertOk()->json('data');
        $this->assertNotSame($data['version'], $next['version']);
        $this->getJson($this->path($org, $data['id']).'/history?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.number', 2)->assertJsonPath('data.0.action', 'updated')->assertJsonPath('data.0.comment', 'Revised');
        $this->getJson($this->path($org, $data['id']).'/history?per_page=1&page=2')->assertJsonPath('data.0.comment', 'Original')
            ->assertJsonPath('data.0.status', 'available');
        $this->assertDatabaseCount('availabilities', 1);
        $this->assertDatabaseCount('availability_revisions', 2);
    }

    public function test_overlap_is_409_but_adjacency_and_other_people_are_allowed(): void
    {
        [$org] = $this->selfEditor();
        $data = $this->postJson($this->path($org), $this->input())->assertCreated()->json('data');
        $this->postJson($this->path($org), [...$this->input(), 'status' => 'unavailable'])->assertConflict()->assertJsonPath('code', 'AVAILABILITY_OVERLAP');
        $adjacent = [...$this->input(), 'starts_at' => '2026-10-18T11:00:00+02:00', 'ends_at' => '2026-10-18T12:00:00+02:00'];
        $this->postJson($this->path($org), $adjacent)->assertCreated();
        $this->patchJson($this->path($org, $data['id']), ['version' => $data['version'], 'starts_at' => '2026-10-18T10:30:00+02:00',
            'ends_at' => '2026-10-18T11:30:00+02:00', 'origin_timezone' => 'Europe/Paris'])->assertConflict();
        $this->assertDatabaseCount('availability_revisions', 2);
        $this->member($org, ['availability.read', 'availability.manage']);
        $other = Person::factory()->create(['organization_id' => $org->id]);
        $this->postJson($this->path($org), [...$this->input(), 'person_id' => $other->id])->assertCreated();
    }

    public function test_stale_edits_and_cancellation_preserve_history_and_release_the_slot(): void
    {
        [$org] = $this->selfEditor();
        $data = $this->postJson($this->path($org), $this->input())->assertCreated()->json('data');
        $path = $this->path($org, $data['id']);
        $this->patchJson($path, ['version' => (string) Str::uuid(), 'status' => 'uncertain'])->assertConflict()->assertJsonPath('code', 'AVAILABILITY_VERSION_STALE');
        $this->patchJson($path, ['version' => $data['version'], 'cancel' => true, 'comment' => 'No'])->assertUnprocessable()->assertJsonValidationErrors('cancel');
        $cancelled = $this->patchJson($path, ['version' => $data['version'], 'cancel' => true])->assertOk()->assertJsonPath('data.can_edit', false)->json('data');
        $this->patchJson($path, ['version' => $cancelled['version'], 'status' => 'uncertain'])->assertConflict()->assertJsonPath('code', 'AVAILABILITY_CANCELLED');
        $this->getJson($this->path($org).$this->window())->assertJsonPath('meta.total', 0);
        $this->getJson($this->path($org).$this->window().'&cancelled=1')->assertJsonPath('meta.total', 1);
        $this->getJson($path.'/history')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.action', 'cancelled');
        $this->postJson($this->path($org), $this->input())->assertCreated();
        $this->deleteJson($path)->assertStatus(405);
        $this->assertDatabaseCount('availabilities', 2);
    }

    public function test_missing_link_and_departure_disable_self_access_and_foreign_tenant_records_are_404(): void
    {
        [$org,$user,$person] = $this->selfEditor();
        $mine = $this->postJson($this->path($org), $this->input())->assertCreated()->json('data');
        $person->forceFill(['status' => 'former'])->save();
        $this->getJson($this->path($org).$this->window())->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('availability.viewer_person_id', null);
        $this->getJson($this->path($org, $mine['id']))->assertNotFound();
        $this->postJson($this->path($org), $this->input())->assertUnprocessable()->assertJsonValidationErrors('person_id');
        $this->member($org, ['availability.read', 'availability.manage']);
        $foreign = Availability::factory()->create();
        $this->getJson($this->path($org, $foreign->id))->assertNotFound();
        $this->patchJson($this->path($org, $foreign->id), ['version' => $foreign->version, 'status' => 'uncertain'])->assertNotFound();
        foreach ([$person->id, Person::factory()->create()->id] as $id) {
            $this->postJson($this->path($org), [...$this->input(), 'person_id' => $id])->assertNotFound();
        }
        // A manager can cancel a retained slot after departure, but cannot rewrite it.
        $this->patchJson($this->path($org, $mine['id']), ['version' => $mine['version'], 'status' => 'uncertain'])->assertNotFound();
        $this->patchJson($this->path($org, $mine['id']), ['version' => $mine['version'], 'cancel' => true])->assertOk();
    }

    public function test_revocation_is_rechecked_after_initial_read(): void
    {
        [$org,$user,$person,$grant] = $this->selfEditor();
        $data = $this->postJson($this->path($org), $this->input())->assertCreated()->json('data');
        $grant->forceFill(['status' => 'revoked'])->save();
        $this->patchJson($this->path($org, $data['id']), ['version' => $data['version'], 'status' => 'uncertain'])->assertForbidden();
        $this->getJson($this->path($org, $data['id']).'/history')->assertForbidden();
        $this->assertDatabaseCount('availability_revisions', 1);
    }

    public function test_window_filters_use_half_open_intervals_and_local_dst_midnights(): void
    {
        [$org,$user,$person] = $this->selfEditor();
        foreach ([['2026-10-24 22:00:00', '2026-10-24 23:00:00'], ['2026-10-24 21:00:00', '2026-10-24 22:00:00'], ['2026-10-25 23:00:00', '2026-10-26 00:00:00']] as [$from,$to]) {
            Availability::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id, 'starts_at' => $from, 'ends_at' => $to]);
        }
        $this->getJson($this->path($org).'?from_date=2026-10-25&to_date=2026-10-26&timezone=Europe%2FParis')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('availability.from_utc', '2026-10-24T22:00:00+00:00')->assertJsonPath('availability.to_utc', '2026-10-25T23:00:00+00:00');
    }

    public function test_real_period_validation_rejects_gaps_offsets_partial_changes_and_invalid_windows(): void
    {
        [$org] = $this->selfEditor();
        $this->postJson($this->path($org), [])->assertUnprocessable()->assertJsonValidationErrors(['status', 'starts_at', 'ends_at', 'origin_timezone']);
        foreach (['organization_id', 'medical_reason', 'comment.*', 'number', 'actor_user_id'] as $field) {
            $this->postJson($this->path($org), [...$this->input(), $field => 'forged'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach ([
            ['starts_at' => '2026-03-29T02:30:00+01:00', 'ends_at' => '2026-03-29T03:30:00+02:00'],
            ['starts_at' => '2026-10-18T10:00:00+01:00'],
            ['ends_at' => '2026-10-18T10:00:00+02:00'],
            ['ends_at' => '2026-12-18T11:00:00+01:00'],
        ] as $change) {
            $this->postJson($this->path($org), [...$this->input(), ...$change])->assertUnprocessable();
        }
        $data = $this->postJson($this->path($org), $this->input())->assertCreated()->json('data');
        $this->patchJson($this->path($org, $data['id']), ['version' => $data['version'], 'starts_at' => '2026-10-18T10:30:00+02:00'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        foreach (['?from_date=2026-01-01&to_date=2026-05-01', '?from_date=2026-01-01&to_date=2026-01-01'] as $query) {
            $this->getJson($this->path($org).$query)->assertUnprocessable()->assertJsonValidationErrors('to_date');
        }
        $this->assertDatabaseCount('availability_revisions', 1);
    }

    public function test_explicit_dst_fold_occurrences_are_distinct_and_adjacent(): void
    {
        [$org] = $this->selfEditor();
        $first = ['status' => 'uncertain', 'starts_at' => '2026-10-25T02:00:00+02:00', 'ends_at' => '2026-10-25T02:00:00+01:00', 'origin_timezone' => 'Europe/Paris'];
        $this->postJson($this->path($org), $first)->assertCreated()->assertJsonPath('data.starts_at', '2026-10-25T00:00:00+00:00')->assertJsonPath('data.ends_at', '2026-10-25T01:00:00+00:00');
        $this->postJson($this->path($org), [...$first, 'starts_at' => '2026-10-25T02:00:00+01:00', 'ends_at' => '2026-10-25T03:00:00+01:00'])->assertCreated();
    }

    public function test_audit_failure_rolls_back_both_current_state_and_snapshot(): void
    {
        [$org] = $this->selfEditor();
        $data = $this->postJson($this->path($org), $this->input())->assertCreated()->json('data');
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Unavailable'));
        $this->patchJson($this->path($org, $data['id']), ['version' => $data['version'], 'status' => 'unavailable'])->assertStatus(500);
        $this->assertDatabaseHas('availabilities', ['id' => $data['id'], 'version' => $data['version'], 'status' => 'available', 'number' => 1]);
        $this->assertDatabaseCount('availability_revisions', 1);
    }

    public function test_batch_labels_do_not_grow_queries_with_page_size(): void
    {
        $org = Organization::factory()->create();
        $user = $this->member($org, ['availability.read', 'availability.manage']);
        Availability::factory()->count(5)->create(['organization_id' => $org->id]);
        $service = app(AvailabilityService::class);
        $context = $this->personContext($org, $user);
        $filters = ['collective' => true, 'from_date' => '2026-10-18', 'to_date' => '2026-10-19'];
        DB::enableQueryLog();
        $service->paginate($context, [...$filters, 'per_page' => 1]);
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->paginate($context, [...$filters, 'per_page' => 100]);
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($small, $large);
        $this->assertLessThan(100, $large);
        $this->assertDatabaseCount('availabilities', 5);
    }

    public function test_uppercase_route_uuid_keeps_its_own_overlap_exclusion_and_zero_comment(): void
    {
        [$org] = $this->selfEditor();
        $data = $this->postJson($this->path($org), $this->input())->assertCreated()->json('data');
        $this->patchJson($this->path($org, strtoupper($data['id'])), ['version' => $data['version'], 'comment' => '0'])
            ->assertOk()->assertJsonPath('data.comment', '0');
        $this->assertDatabaseCount('availability_revisions', 2);
    }

    /** @return array{Organization,User,Person,OrganizationRoleAssignment} */
    private function selfEditor(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $grant = $this->grantPeopleAccess($org, $user, ['availability.self']);
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'pseudonym' => 'Camille', 'timezone' => 'Asia/Tokyo']);
        $this->actingAsWithMfa($user);

        return [$org, $user, $person, $grant];
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

    private function path(Organization $org, ?string $id = null): string
    {
        return $this->root($org).'/availabilities'.($id === null ? '' : '/'.$id);
    }

    private function window(): string
    {
        return '?from_date=2026-10-18&to_date=2026-10-19';
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        return ['status' => 'available', 'starts_at' => '2026-10-18T10:00:00+02:00', 'ends_at' => '2026-10-18T11:00:00+02:00', 'origin_timezone' => 'Europe/Paris'];
    }
}
