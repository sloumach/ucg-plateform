<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Application\Services\CalendarService;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarParticipant;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
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

class CalendarControllerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesPeopleAccess;

    public function test_guest_and_reader_cannot_create_or_enumerate_editor_references(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->path($org).$this->window())->assertUnauthorized();
        $this->member($org, ['calendar.read']);
        $this->postJson($this->path($org), $this->input())->assertForbidden();
        $this->getJson($this->root($org).'/people')->assertForbidden();
        $this->getJson($this->root($org).'/teams')->assertForbidden();
        $this->getJson($this->path($org).$this->window().'&management=1')->assertForbidden();
    }

    public function test_creation_stores_utc_and_encrypted_private_data_with_minimal_atomic_audit(): void
    {
        $org = $this->editor();
        Http::preventStrayRequests();
        Mail::fake();
        $person = Person::factory()->create(['organization_id' => $org->id, 'pseudonym' => 'Joueuse']);
        $data = $this->postJson($this->path($org), [...$this->input(), 'kind' => 'scrim', 'participant_ids' => [$person->id],
            'description' => 'SECRET_DESCRIPTION', 'location' => 'SECRET_LOCATION', 'url' => 'https://meeting.example.test/room?token=SECRET_TOKEN'])
            ->assertCreated()->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.starts_at', '2026-10-18T08:00:00+00:00')
            ->assertJsonPath('data.starts_at_origin', '2026-10-18T10:00:00+02:00')->assertJsonPath('data.participants.0.label', 'Joueuse')
            ->assertJsonPath('notification.message', 'L’activité du calendrier a été enregistrée.')->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.organization_id')->json('data');
        $stored = DB::table('calendar_activities')->where('id', $data['id'])->firstOrFail();
        foreach (['title', 'description', 'location', 'url'] as $field) {
            $this->assertStringNotContainsString('SECRET_', $stored->$field);
        }
        $audit = json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SECRET_', $audit);
        $this->assertDatabaseCount('calendar_activities', 1);
        $this->assertDatabaseCount('calendar_participants', 1);
        $this->assertDatabaseCount('organization_audit_events', 1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_sharing_requires_publish_permission_confirmation_and_team_for_team_visibility(): void
    {
        $org = $this->editor();
        $this->postJson($this->path($org), [...$this->input(), 'visibility' => 'organization', 'confirm_sharing' => true])->assertForbidden();
        $this->member($org, ['calendar.read', 'calendar.write', 'calendar.publish']);
        $this->postJson($this->path($org), [...$this->input(), 'visibility' => 'organization'])->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');
        $this->postJson($this->path($org), [...$this->input(), 'visibility' => 'team', 'confirm_sharing' => true])->assertUnprocessable()->assertJsonValidationErrors('team_id');
        $data = $this->postJson($this->path($org), [...$this->input(), 'visibility' => 'organization', 'confirm_sharing' => true])->assertCreated()->json('data');
        $this->patchJson($this->path($org, $data['id']), ['version' => $data['version'], 'title' => 'Rename'])->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');
        $this->patchJson($this->path($org, $data['id']), ['version' => $data['version'], 'visibility' => 'private'])->assertOk();
        $this->assertDatabaseHas('calendar_activities', ['id' => $data['id'], 'sharing_authorized_at' => null, 'sharing_authorized_by' => null]);
    }

    public function test_private_visibility_uses_current_participant_account_and_filters_before_counting(): void
    {
        $org = $this->editor();
        $reader = $this->member($org, ['calendar.read']);
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $reader->id, 'timezone' => 'Asia/Tokyo']);
        $hidden = CalendarActivity::factory()->create($this->attributes($org));
        $mine = CalendarActivity::factory()->create($this->attributes($org));
        CalendarParticipant::factory()->create(['organization_id' => $org->id, 'activity_id' => $mine->id, 'person_id' => $person->id]);
        CalendarActivity::factory()->shared()->create($this->attributes($org));
        $this->getJson($this->path($org).$this->window())->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('calendar.viewer_timezone', 'Asia/Tokyo');
        $this->getJson($this->path($org, $hidden->id))->assertNotFound();
        $this->getJson($this->path($org, $mine->id))->assertOk();
        $person->forceFill(['status' => 'former'])->save();
        $this->getJson($this->path($org, $mine->id))->assertNotFound();
        $this->assertDatabaseCount('calendar_participants', 1);
    }

    public function test_team_visibility_is_revoked_at_current_roster_period_end(): void
    {
        $this->travelTo('2026-10-17 12:00:00');
        $org = $this->editor();
        $reader = $this->member($org, ['calendar.read']);
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $reader->id]);
        $team = Team::factory()->create(['organization_id' => $org->id]);
        TeamMembership::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id, 'team_id' => $team->id, 'ends_at' => now()->addHour()]);
        $item = CalendarActivity::factory()->shared()->create([...$this->attributes($org), 'visibility' => 'team', 'team_id' => $team->id]);
        $this->getJson($this->path($org).$this->window())->assertJsonPath('meta.total', 1);
        $this->travel(1)->hours();
        $this->getJson($this->path($org).$this->window())->assertJsonPath('meta.total', 0);
        $this->getJson($this->path($org, $item->id))->assertNotFound();
    }

    public function test_bound_window_follows_viewer_timezone_and_half_open_overlap(): void
    {
        $org = $this->editor(['calendar.read']);
        CalendarActivity::factory()->shared()->create([...$this->attributes($org), 'starts_at' => '2026-10-24 22:00:00', 'ends_at' => '2026-10-24 23:00:00']);
        CalendarActivity::factory()->shared()->create([...$this->attributes($org), 'starts_at' => '2026-10-24 21:00:00', 'ends_at' => '2026-10-24 22:00:00']);
        CalendarActivity::factory()->shared()->create([...$this->attributes($org), 'starts_at' => '2026-10-25 23:00:00', 'ends_at' => '2026-10-26 00:00:00']);
        $this->getJson($this->path($org).'?from_date=2026-10-25&to_date=2026-10-26&timezone=Europe%2FParis')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('calendar.from_utc', '2026-10-24T22:00:00+00:00')->assertJsonPath('calendar.to_utc', '2026-10-25T23:00:00+00:00');
    }

    public function test_versions_archive_and_unknown_context_fields_are_enforced(): void
    {
        $org = $this->editor();
        $record = $this->postJson($this->path($org), $this->input())->assertCreated()->json('data');
        $url = $this->path($org, $record['id']);
        $this->patchJson($url, ['version' => (string) Str::uuid(), 'title' => 'Stale'])->assertConflict()->assertJsonPath('code', 'CALENDAR_VERSION_STALE');
        $this->patchJson($url, ['version' => $record['version']])->assertUnprocessable();
        $this->patchJson($url, ['version' => $record['version'], 'archive' => true, 'title' => 'Mix'])->assertUnprocessable();
        $archived = $this->patchJson($url, ['version' => $record['version'], 'archive' => true])->assertOk()->json('data');
        $this->patchJson($url, ['version' => $archived['version'], 'title' => 'No'])->assertConflict()->assertJsonPath('code', 'CALENDAR_ARCHIVED');
        $this->getJson($this->path($org).$this->window())->assertJsonPath('meta.total', 0);
        $this->getJson($this->path($org).$this->window().'&management=1&archived=1')->assertJsonPath('meta.total', 1);
        $this->deleteJson($url)->assertStatus(405);
        foreach (['match_id', 'event_id', 'cohort_id', 'organization_id', 'created_by', 'recurrence', 'participant_ids.*'] as $field) {
            $this->postJson($this->path($org), [...$this->input(), $field => 'forged'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_validation_rejects_incoherent_periods_and_date_window_and_insecure_links(): void
    {
        $org = $this->editor();
        $url = $this->path($org);
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'kind', 'starts_at', 'ends_at', 'origin_timezone']);
        $this->postJson($url, [...$this->input(), 'starts_at' => '2026-03-29T02:30:00+01:00'])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->postJson($url, [...$this->input(), 'ends_at' => '2026-10-18T09:00:00+02:00'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->postJson($url, [...$this->input(), 'url' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonPath('errors.url.0', 'Utilisez un lien HTTPS sans identifiants, port explicite ou caractères de contrôle.');
        foreach (['?from_date=2026-01-01&to_date=2026-05-01', '?from_date=2026-01-01&to_date=2026-01-01', '?from_date=2026-02-30&to_date=2026-03-02'] as $window) {
            $this->getJson($url.$window)->assertUnprocessable();
        }
        $this->getJson($url.$this->window().'&per_page=101')->assertUnprocessable();
        $this->getJson($url.$this->window().'&sort=title')->assertUnprocessable();
        $record = $this->postJson($url, $this->input())->assertCreated()->json('data');
        $this->patchJson($this->path($org, $record['id']), ['version' => $record['version'], 'starts_at' => '2026-10-18T11:00:00+02:00'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_cross_tenant_and_departed_references_are_rejected_without_changes(): void
    {
        $org = $this->editor();
        $foreign = CalendarActivity::factory()->create();
        $this->getJson($this->path($org, $foreign->id).'?management=1')->assertNotFound();
        $this->patchJson($this->path($org, $foreign->id), ['version' => $foreign->version, 'title' => 'No'])->assertNotFound();
        $this->postJson($this->path($org), [...$this->input(), 'team_id' => Team::factory()->create()->id])->assertNotFound();
        $person = Person::factory()->create(['organization_id' => $org->id, 'status' => 'former']);
        foreach ([$person->id, Person::factory()->create()->id] as $id) {
            $this->postJson($this->path($org), [...$this->input(), 'participant_ids' => [$id]])->assertNotFound();
        }
        $this->assertDatabaseCount('calendar_activities', 1);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_reference_pagination_and_eager_loading_have_constant_query_counts(): void
    {
        $org = $this->editor();
        $user = User::findOrFail(auth()->id());
        Person::factory()->count(2)->create(['organization_id' => $org->id]);
        Person::factory()->create();
        Team::factory()->create(['organization_id' => $org->id]);
        $this->getJson($this->root($org).'/people?per_page=1')->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.user_id');
        $this->getJson($this->root($org).'/teams')->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.game_id');
        CalendarActivity::factory()->count(5)->create([...$this->attributes($org), 'created_by' => $user->id]);
        $service = app(CalendarService::class);
        $context = $this->personContext($org, $user);
        $filters = ['from_date' => '2026-10-18', 'to_date' => '2026-10-19'];
        DB::enableQueryLog();
        $service->paginate($context, [...$filters, 'per_page' => 1]);
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->paginate($context, [...$filters, 'per_page' => 100]);
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($small, $large);
        $this->assertLessThan(60, $large);
    }

    public function test_audit_failure_rolls_back_activity_and_participants(): void
    {
        $org = $this->editor();
        $person = Person::factory()->create(['organization_id' => $org->id]);
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Unavailable'));
        $this->postJson($this->path($org), [...$this->input(), 'participant_ids' => [$person->id]])->assertStatus(500);
        $this->assertDatabaseCount('calendar_activities', 0);
        $this->assertDatabaseCount('calendar_participants', 0);
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

    private function path(Organization $org, ?string $id = null): string
    {
        return $this->root($org).'/activities'.($id === null ? '' : '/'.$id);
    }

    private function window(): string
    {
        return '?from_date=2026-10-18&to_date=2026-10-19';
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        return ['title' => 'SECRET_TITLE', 'kind' => 'training', 'starts_at' => '2026-10-18T10:00:00+02:00', 'ends_at' => '2026-10-18T11:00:00+02:00', 'origin_timezone' => 'Europe/Paris'];
    }

    /** @return array<string,mixed> */
    private function attributes(Organization $org): array
    {
        return ['organization_id' => $org->id, 'starts_at' => '2026-10-18 08:00:00', 'ends_at' => '2026-10-18 09:00:00'];
    }
}
