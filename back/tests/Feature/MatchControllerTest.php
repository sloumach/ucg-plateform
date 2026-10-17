<?php

namespace Tests\Feature;

use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Domain\Models\MatchStatusEvent;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Application\Contracts\ExternalOpponents;
use App\Modules\Teams\Domain\Models\ExternalOpponent;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatches;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMatches, ProvidesPeopleAccess;

    public function test_guest_has_no_match_access(): void
    {
        $this->getJson('/api/v1/tenants/11111111-1111-4111-8111-111111111111/matches')->assertUnauthorized();
    }

    public function test_creation_retains_private_snapshots_without_creating_calendar_or_results(): void
    {
        [$org, , $reader, $person, $other, $game] = $this->matchScenario();
        $data = $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.status', 'proposed')->assertJsonCount(2, 'data.participants')
            ->assertJsonPath('data.participants.0.label', 'Joueur pilote')->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.score')->json('data');
        $this->assertDatabaseCount('matches', 1);
        $this->assertDatabaseCount('match_participants', 2);
        $this->assertDatabaseCount('match_status_events', 1);
        $this->assertDatabaseCount('calendar_activities', 0);
        $this->assertDatabaseCount('organization_audit_events', 1);
        $this->assertNotSame($data['title'], DB::table('matches')->value('title'));
        $person->forceFill(['pseudonym' => 'Nouveau pseudonyme'])->save();
        $this->actingAsWithMfa($reader);
        $this->getJson($this->matchRoot($org).'/'.$data['id'])->assertOk()->assertJsonPath('data.participants.0.label', 'Joueur pilote');
        $this->getJson($this->matchRoot($org).'/'.$data['id'].'/history')->assertOk()->assertJsonPath('data.0.to_status', 'proposed')->assertJsonMissingPath('data.0.actor_user_id');
    }

    public function test_reader_write_and_foreign_tenant_requests_are_refused(): void
    {
        [$org, $writer, $reader, $person, $other, $game] = $this->matchScenario();
        $foreign = MatchRecord::factory()->create();
        $this->getJson($this->matchRoot($org).'/'.$foreign->id.'?management=1')->assertNotFound();
        $this->patchJson($this->matchRoot($org).'/'.$foreign->id, ['version' => $foreign->version, 'title' => 'Intrusion'])->assertNotFound();
        $this->actingAsWithMfa($reader);
        $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertForbidden();
        $this->getJson($this->matchRoot($org).'?management=1')->assertForbidden();
        $this->getJson($this->matchRoot($org).'/references/person')->assertForbidden();
        $this->actingAsWithMfa($writer);
        $foreignPerson = Person::factory()->create();
        $this->postJson($this->matchRoot($org), [...$this->matchPayload($game, $person, $other), 'responsible_person_id' => $foreignPerson->id])->assertNotFound();
        $this->assertDatabaseCount('matches', 1);
    }

    public function test_lobby_supports_more_than_two_individual_participants_but_bo_formats_do_not(): void
    {
        [$org, , , $person, $other, $game] = $this->matchScenario();
        $third = Person::factory()->create(['organization_id' => $org->id]);
        $payload = $this->matchPayload($game, $person, $other);
        $payload['participants'][] = ['kind' => 'person', 'source_id' => $third->id];
        $this->postJson($this->matchRoot($org), $payload)->assertUnprocessable()->assertJsonValidationErrors('participants');
        $this->postJson($this->matchRoot($org), [...$payload, 'format' => 'lobby'])->assertCreated()->assertJsonCount(3, 'data.participants');
        $this->assertDatabaseCount('matches', 1);
    }

    /** @return array<string,array{string,mixed}> */
    public static function rejectedInputs(): array
    {
        return ['format' => ['format', 'invented'], 'foreign status' => ['status', 'validated'], 'score' => ['score', 2],
            'owner' => ['organization_id', '11111111-1111-4111-8111-111111111111'], 'end before start' => ['ends_at', '2026-10-19T09:00:00+00:00'],
            'wrong offset' => ['starts_at', '2026-10-19T10:00:00+02:00'], 'unknown nested field' => ['participants', [['kind' => 'person', 'source_id' => '11111111-1111-4111-8111-111111111111', 'score' => 3]]]];
    }

    #[DataProvider('rejectedInputs')]
    public function test_closed_validated_inputs_make_no_partial_match(string $field, mixed $value): void
    {
        [$org, , , $person, $other, $game] = $this->matchScenario();
        $this->postJson($this->matchRoot($org), [...$this->matchPayload($game, $person, $other), $field => $value])->assertUnprocessable();
        $this->assertDatabaseCount('matches', 0);
        $this->assertDatabaseCount('match_status_events', 0);
    }

    public function test_duplicate_participants_and_partial_period_are_rejected(): void
    {
        [$org, , , $person, $other, $game] = $this->matchScenario();
        $payload = $this->matchPayload($game, $person, $other);
        $payload['participants'][1] = $payload['participants'][0];
        $this->postJson($this->matchRoot($org), $payload)->assertUnprocessable()->assertJsonValidationErrors('participants');
        $data = $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated()->json('data');
        $this->patchJson($this->matchRoot($org).'/'.$data['id'], ['version' => $data['version'], 'starts_at' => '2026-10-20T10:00:00+00:00'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->assertDatabaseHas('matches', ['id' => $data['id'], 'version' => $data['version']]);
    }

    public function test_sharing_requires_a_distinct_permission_and_explicit_confirmation(): void
    {
        [$org, $writer, $reader, $person, $other, $game] = $this->matchScenario();
        $payload = [...$this->matchPayload($game, $person, $other), 'visibility' => 'organization'];
        $this->postJson($this->matchRoot($org), [...$payload, 'confirm_sharing' => true])->assertForbidden();
        $role = OrganizationRole::query()->where('organization_id', $org->id)->whereJsonContains('permissions', 'sports.write')->sole();
        $role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.publish']])->save();
        $this->postJson($this->matchRoot($org), $payload)->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');
        $this->postJson($this->matchRoot($org), [...$payload, 'confirm_sharing' => true])->assertCreated();
        $this->assertDatabaseCount('matches', 1);
    }

    public function test_private_and_team_audiences_are_filtered_before_pagination_and_follow_membership(): void
    {
        [$org, $writer, $reader, $person, $other, $game] = $this->matchScenario();
        $stranger = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $stranger, ['sports.read']);
        $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated();
        $this->actingAsWithMfa($stranger);
        $this->getJson($this->matchRoot($org).'?per_page=1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAsWithMfa($writer);
        $role = OrganizationRole::query()->where('organization_id', $org->id)->whereJsonContains('permissions', 'sports.write')->sole();
        $role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.publish']])->save();
        $team = Team::factory()->create(['organization_id' => $org->id, 'game_id' => $game->id, 'status' => 'active']);
        $period = TeamMembership::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id, 'team_id' => $team->id, 'starts_at' => now()->subDay()]);
        $payload = $this->matchPayload($game, $person, $other);
        $payload['responsible_person_id'] = $other->id;
        $payload['participants'] = [['kind' => 'team', 'source_id' => $team->id], ['kind' => 'person', 'source_id' => $other->id]];
        $teamMatch = $this->postJson($this->matchRoot($org), [...$payload, 'visibility' => 'team', 'confirm_sharing' => true])->assertCreated()->json('data');
        $this->actingAsWithMfa($reader);
        $this->getJson($this->matchRoot($org).'/'.$teamMatch['id'])->assertOk();
        $period->forceFill(['ends_at' => now()])->save();
        $this->getJson($this->matchRoot($org).'/'.$teamMatch['id'])->assertNotFound();
    }

    public function test_transitions_are_versioned_motivated_and_freeze_confirmed_core(): void
    {
        [$org, , , $person, $other, $game] = $this->matchScenario();
        $data = $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated()->json('data');
        $path = $this->matchRoot($org).'/'.$data['id'];
        $this->postJson($path.'/transitions', ['version' => $data['version'], 'status' => 'confirmed', 'reason' => 'Court'])->assertUnprocessable();
        $next = $this->postJson($path.'/transitions', ['version' => $data['version'], 'status' => 'confirmed', 'reason' => 'Participants et créneau vérifiés.'])->assertOk()->assertJsonPath('data.editable', false)->json('data');
        $this->postJson($path.'/transitions', ['version' => $data['version'], 'status' => 'cancelled', 'reason' => 'Demande concurrente devenue obsolète.'])->assertConflict()->assertJsonPath('code', 'MATCH_VERSION_STALE');
        $this->patchJson($path, ['version' => $next['version'], 'title' => 'Correction interdite'])->assertConflict()->assertJsonPath('code', 'MATCH_NOT_EDITABLE');
        $reopened = $this->postJson($path.'/transitions', ['version' => $next['version'], 'status' => 'proposed', 'reason' => 'Réouverture explicite pour correction.'])->assertOk()->json('data');
        $this->patchJson($path, ['version' => $reopened['version'], 'title' => 'Titre corrigé'])->assertOk();
        $this->getJson($path.'/history')->assertJsonPath('meta.total', 3);
        $this->assertNotSame('Réouverture explicite pour correction.', MatchStatusEvent::query()->latest('id')->firstOrFail()->getRawOriginal('reason'));
    }

    public function test_result_validation_cannot_be_fabricated_and_cancelled_match_is_terminal(): void
    {
        [$org, , , $person, $other, $game] = $this->matchScenario();
        $data = $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated()->json('data');
        $path = $this->matchRoot($org).'/'.$data['id'];
        foreach (['confirmed', 'in_progress', 'awaiting_validation'] as $status) {
            $data = $this->postJson($path.'/transitions', ['version' => $data['version'], 'status' => $status, 'reason' => 'Passage opérationnel explicitement décidé.'])->assertOk()->json('data');
        }
        $this->postJson($path.'/transitions', ['version' => $data['version'], 'status' => 'validated', 'reason' => 'Tentative sans dossier de résultats.'])->assertConflict()->assertJsonPath('code', 'MATCH_RESULT_WORKFLOW_REQUIRED');
        $this->assertDatabaseCount('match_status_events', 4);
        $second = $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated()->json('data');
        $path = $this->matchRoot($org).'/'.$second['id'];
        $cancelled = $this->postJson($path.'/transitions', ['version' => $second['version'], 'status' => 'cancelled', 'reason' => 'Rencontre annulée avant confirmation.'])->assertOk()->json('data');
        $this->postJson($path.'/transitions', ['version' => $cancelled['version'], 'status' => 'proposed', 'reason' => 'Réactivation non autorisée du dossier.'])->assertConflict()->assertJsonPath('code', 'MATCH_TRANSITION_INVALID');
    }

    public function test_audit_failure_rolls_back_the_match_participants_and_history(): void
    {
        [$org, , , $person, $other, $game] = $this->matchScenario();
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertStatus(500);
        $this->assertDatabaseCount('matches', 0);
        $this->assertDatabaseCount('match_participants', 0);
        $this->assertDatabaseCount('match_status_events', 0);
    }

    public function test_references_are_paginated_minimal_and_closed(): void
    {
        [$org] = $this->matchScenario();
        $this->getJson($this->matchRoot($org).'/references/person?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonMissingPath('data.0.email');
        $this->getJson($this->matchRoot($org).'?per_page=101')->assertUnprocessable();
        $this->getJson($this->matchRoot($org).'?sort=title desc')->assertUnprocessable();
        $this->getJson($this->matchRoot($org).'/references/organization')->assertNotFound();
    }

    public function test_external_participant_uses_only_an_authorized_historical_snapshot(): void
    {
        [$org, $writer, , $person, $other, $game] = $this->matchScenario();
        $role = OrganizationRole::query()->where('organization_id', $org->id)->whereJsonContains('permissions', 'sports.write')->sole();
        $role->forceFill(['permissions' => ['sports.read', 'sports.write', 'teams.manage']])->save();
        $opponent = ExternalOpponent::factory()->create(['organization_id' => $org->id, 'game_id' => $game->id, 'name' => 'Adversaire public']);
        $snapshot = app(ExternalOpponents::class)->capture($this->personContext($org, $writer), $opponent->id, $opponent->version, 'Préparation du scrim', true);
        $payload = $this->matchPayload($game, $person, $other);
        $payload['participants'][1] = ['kind' => 'opponent_snapshot', 'source_id' => $snapshot['id']];
        $data = $this->postJson($this->matchRoot($org), $payload)->assertCreated()->assertJsonPath('data.participants.1.label', 'Adversaire public')
            ->assertJsonMissingPath('data.participants.1.source_url')->json('data');
        $opponent->forceFill(['name' => 'Adversaire renommé'])->save();
        $this->getJson($this->matchRoot($org).'/'.$data['id'])->assertJsonPath('data.participants.1.label', 'Adversaire public');
        $this->getJson($this->matchRoot($org).'/references/opponent_snapshot')->assertJsonPath('meta.total', 1);
    }

    public function test_departed_participant_and_disabled_game_prevent_confirmation_but_allow_cancellation(): void
    {
        [$org, , , $person, $other, $game] = $this->matchScenario();
        $data = $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated()->json('data');
        $path = $this->matchRoot($org).'/'.$data['id'].'/transitions';
        $other->forceFill(['status' => 'former'])->save();
        $this->postJson($path, ['version' => $data['version'], 'status' => 'confirmed', 'reason' => 'Vérification avant la confirmation.'])->assertNotFound();
        $game->forceFill(['active' => false])->save();
        $this->postJson($path, ['version' => $data['version'], 'status' => 'confirmed', 'reason' => 'Vérification avant la confirmation.'])->assertConflict()->assertJsonPath('code', 'GAME_CONTEXT_UNAVAILABLE');
        $this->postJson($path, ['version' => $data['version'], 'status' => 'cancelled', 'reason' => 'Annulation après retrait des participants.'])->assertOk();
        $this->assertDatabaseCount('match_status_events', 2);
    }

    public function test_person_snapshots_and_list_participants_are_loaded_in_groups(): void
    {
        [$org, , , $person, $other, $game] = $this->matchScenario();
        $payload = $this->matchPayload($game, $person, $other);
        $extra = Person::factory()->count(8)->create(['organization_id' => $org->id]);
        foreach ($extra as $p) {
            $payload['participants'][] = ['kind' => 'person', 'source_id' => $p->id];
        }
        $payload['format'] = 'lobby';
        DB::enableQueryLog();
        $this->postJson($this->matchRoot($org), $payload)->assertCreated()->assertJsonCount(10, 'data.participants');
        $writes = DB::getQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->matchRoot($org))->assertOk()->assertJsonCount(10, 'data.0.participants');
        $reads = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, array_filter($writes, fn (array $q): bool => str_starts_with($q['query'], 'select "id", "pseudonym", "version" from "people"')));
        $this->assertCount(1, array_filter($reads, fn (array $q): bool => str_starts_with($q['query'], 'select * from "match_participants"')));
    }
}
