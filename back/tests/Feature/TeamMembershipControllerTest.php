<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class TeamMembershipControllerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesPeopleAccess;

    public function test_guest_and_reader_cannot_mutate_rosters(): void
    {
        $team = Team::factory()->create();
        $this->getJson($this->path($team))->assertUnauthorized();
        $person = Person::factory()->create(['organization_id' => $team->organization_id]);
        $org = Organization::findOrFail($team->organization_id);
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read']);
        $this->actingAsWithMfa($user);
        $this->getJson($this->path($team))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->path($team), $this->payload($person))->assertForbidden();
        $this->assertDatabaseCount('team_memberships', 0);
    }

    public function test_create_returns_only_sporting_identity_and_traces_author_without_private_fields(): void
    {
        [$team,$person] = $this->editor();
        $created = $this->postJson($this->path($team), $this->payload($person))->assertCreated()->assertJsonPath('data.role_key', 'player')->assertJsonPath('data.effective', true)
            ->assertJsonMissingPath('data.organization_id')->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.contact_email');
        $this->assertDatabaseCount('team_memberships', 1);
        $this->assertDatabaseCount('team_movements', 1);
        $this->assertDatabaseCount('organization_audit_events', 1);
        $this->getJson($this->path($team))->assertOk()->assertJsonPath('data.0.pseudonym', 'Camille')->assertJsonMissingPath('data.0.contact_email');
        $this->getJson($this->base($team).'/movements')->assertOk()->assertJsonPath('data.0.action', 'created')->assertJsonPath('data.0.actor_user_id', auth()->id());
        $this->assertDatabaseHas('team_memberships', ['id' => $created->json('data.id'), 'person_id' => $person->id, 'team_id' => $team->id]);
    }

    public function test_roster_filtering_is_paginated_and_historical_half_open_dates_are_exact(): void
    {
        $this->freezeSecond();
        [$team,$person] = $this->editor();
        $a = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'role_key' => 'substitute', 'starts_at' => now()->subDay()]);
        $this->getJson($this->path($team).'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->assertNotNull($a->ends_at);
        $query = http_build_query(['at' => $a->ends_at->toIso8601String()]);
        $this->getJson($this->path($team).'?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.role_key', 'substitute')->assertJsonPath('data.0.effective', true);
        $this->getJson($this->path($team).'?role_key=coach')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($team).'?per_page=101')->assertUnprocessable();
        $this->getJson($this->base($team).'/movements?per_page=101')->assertUnprocessable();
    }

    public function test_known_foreign_people_teams_and_membership_ids_return_404(): void
    {
        [$team,$person] = $this->editor();
        $foreign = TeamMembership::factory()->create();
        $this->postJson($this->path($team), $this->payload(Person::findOrFail($foreign->person_id)))->assertNotFound();
        $this->patchJson($this->path($team).'/'.$foreign->id, ['version' => $foreign->version, 'action' => 'cancel', 'reason_key' => 'other'])->assertNotFound();
        $this->getJson($this->path(Team::findOrFail($foreign->team_id)))->assertNotFound();
        $this->assertDatabaseCount('team_movements', 0);
    }

    public function test_stale_version_and_terminal_closure_do_not_rewrite_history(): void
    {
        $this->freezeSecond();
        [$team,$person] = $this->editor();
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        $body = ['version' => $period->version, 'action' => 'close', 'reason_key' => 'end_of_period', 'effective_at' => now()->toIso8601String()];
        $this->patchJson($this->path($team).'/'.$period->id, [...$body, 'version' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'TEAM_MEMBERSHIP_VERSION_STALE');
        $response = $this->patchJson($this->path($team).'/'.$period->id, $body)->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.effective', false);
        $this->patchJson($this->path($team).'/'.$period->id, [...$body, 'version' => $response->json('data.version')])->assertConflict()->assertJsonPath('code', 'TEAM_MEMBERSHIP_IMMUTABLE');
        $this->assertDatabaseCount('team_movements', 1);
    }

    public function test_future_cancellation_frees_the_period_and_a_past_assignment_cannot_be_cancelled(): void
    {
        $this->freezeSecond();
        [$team,$person] = $this->editor();
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'starts_at' => now()->addDay()]);
        $this->patchJson($this->path($team).'/'.$period->id, ['version' => $period->version, 'action' => 'cancel', 'reason_key' => 'other'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson($this->path($team), [...$this->payload($person), 'starts_at' => now()->addDay()->toIso8601String()])->assertCreated();
        $past = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'role_key' => 'coach']);
        $this->patchJson($this->path($team).'/'.$past->id, ['version' => $past->version, 'action' => 'cancel', 'reason_key' => 'other'])->assertUnprocessable();
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function invalid(): array
    {
        return ['role' => [['role_key' => 'owner'], 'role_key'], 'person' => [['person_id' => 'invalid'], 'person_id'],
            'unknown' => [['organization_id' => 'spoof'], 'organization_id'], 'start' => [['starts_at' => 'yesterday'], 'starts_at'],
            'reason' => [['reason_key' => 'free-private-text'], 'reason_key'], 'missing' => [['reason_key' => null], 'reason_key'],
            'end format' => [['ends_at' => '2026-10-13'], 'ends_at']];
    }

    /** @param array<string,mixed> $change */
    #[DataProvider('invalid')]
    public function test_invalid_creation_is_localized_and_has_no_partial_writes(array $change, string $field): void
    {
        [$team,$person] = $this->editor();
        $response = $this->postJson($this->path($team), [...$this->payload($person), ...$change])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertNotEmpty($response->json('errors.'.$field.'.0'));
        $this->assertDatabaseCount('team_memberships', 0);
        $this->assertDatabaseCount('team_movements', 0);
    }

    public function test_empty_or_reversed_period_is_rejected(): void
    {
        $this->freezeSecond();
        [$team,$person] = $this->editor();
        $body = $this->payload($person);
        $this->postJson($this->path($team), [...$body, 'ends_at' => $body['starts_at']])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->assertDatabaseCount('team_memberships', 0);
    }

    public function test_archived_and_unavailable_contexts_block_new_uses_but_closure_remains_possible(): void
    {
        $this->freezeSecond();
        [$team,$person] = $this->editor();
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        $this->patchJson($this->base($team), ['version' => $team->version, 'status' => 'archived'])->assertConflict()->assertJsonPath('code', 'TEAM_HAS_LIVE_MEMBERSHIPS');
        OrganizationGame::factory()->create(['organization_id' => $team->organization_id, 'game_id' => $team->game_id, 'enabled' => false]);
        $this->postJson($this->path($team), [...$this->payload($person), 'role_key' => 'coach'])->assertConflict()->assertJsonPath('code', 'GAME_CONTEXT_UNAVAILABLE');
        $this->patchJson($this->path($team).'/'.$period->id, ['version' => $period->version, 'action' => 'close', 'reason_key' => 'other', 'effective_at' => now()->toIso8601String()])->assertOk();
        $this->patchJson($this->base($team), ['version' => $team->version, 'status' => 'archived'])->assertOk();
        $this->postJson($this->path($team), $this->payload($person))->assertConflict()->assertJsonPath('code', 'TEAM_ARCHIVED');
    }

    public function test_mfa_and_human_tenant_confirmation_protect_writes(): void
    {
        [$team,$person] = $this->editor();
        $user = User::findOrFail(auth()->id());
        $this->withSession([MfaSession::PROOF_KEY => null])->postJson($this->path($team), $this->payload($person))->assertForbidden();
        $this->actingAsWithMfa($user);
        $this->getJson($this->path($team))->assertOk();
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $team->organization_id, 'actor_user_id' => $user->id, 'revision' => $revision, 'confirmed' => false]])
            ->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision)
            ->postJson($this->path($team), $this->payload($person))->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
    }

    public function test_movement_requires_localized_destination_fields_and_closure_requires_a_date_after_start(): void
    {
        $this->freezeSecond();
        [$team,$person] = $this->editor();
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        $path = $this->path($team).'/'.$period->id;
        $this->patchJson($path, ['version' => $period->version, 'action' => 'transfer', 'reason_key' => 'transfer'])->assertUnprocessable()
            ->assertJsonValidationErrors(['destination_team_id', 'destination_version', 'role_key'])->assertJsonPath('errors.destination_team_id.0', 'Ce champ est obligatoire.');
        $this->patchJson($path, ['version' => $period->version, 'action' => 'close', 'reason_key' => 'other', 'effective_at' => $period->starts_at->toIso8601String()])->assertUnprocessable()
            ->assertJsonValidationErrors('effective_at')->assertJsonPath('errors.effective_at.0', 'La fin doit être strictement postérieure au début.');
        $this->assertDatabaseCount('team_movements', 0);
        $this->assertDatabaseHas('team_memberships', ['id' => $period->id, 'version' => $period->version, 'status' => 'active']);
    }

    public function test_sporting_names_are_loaded_once_for_the_entire_page(): void
    {
        [$team,$person] = $this->editor();
        TeamMembership::factory()->count(5)->create(['team_id' => $team->id]);
        DB::enableQueryLog();
        $this->getJson($this->path($team).'?per_page=5')->assertOk()->assertJsonCount(5, 'data');
        $queries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "people"'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
    }

    /** @return array{Team,Person} */
    private function editor(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'teams.manage']);
        $this->actingAsWithMfa($user);
        $team = Team::factory()->create(['organization_id' => $org->id]);

        return [$team, Person::factory()->create(['organization_id' => $org->id, 'pseudonym' => 'Camille', 'status' => 'active', 'contact_email' => 'private@example.test'])];
    }

    private function base(Team $team): string
    {
        return '/api/v1/tenants/'.$team->organization_id.'/teams/'.$team->id;
    }

    private function path(Team $team): string
    {
        return $this->base($team).'/memberships';
    }

    /** @return array<string,mixed> */
    private function payload(Person $person): array
    {
        return ['person_id' => $person->id, 'role_key' => 'player', 'starts_at' => now()->subDay()->startOfSecond()->toIso8601String(), 'reason_key' => 'initial'];
    }
}
