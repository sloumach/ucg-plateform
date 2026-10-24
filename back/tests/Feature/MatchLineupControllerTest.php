<?php

namespace Tests\Feature;

use App\Modules\Competition\Domain\Models\MatchLineupRevision;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Teams\Application\Services\LineupEligibilityService;
use App\Modules\Teams\Application\Services\TeamLineupService;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamLineupEntry;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchLineups;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchLineupControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMatchLineups, ProvidesPeopleAccess;

    public function test_assignment_and_substitution_retain_author_time_and_frozen_names(): void
    {
        $s = $this->matchLineupScenario();
        $this->getJson($s['path'].'/choices')->assertOk()->assertJsonPath('data.0.id', $s['lineup']->id);
        $assigned = $this->postJson($s['path'], ['version' => $s['match']->version, 'lineup_id' => $s['lineup']->id, 'reason' => 'Sélection retenue avant la rencontre.'])
            ->assertCreated()->assertJsonPath('notification.type', 'success')->assertJsonPath('data.actor_user_id', $s['writer']->id)
            ->assertJsonPath('data.occurred_at', '2026-10-18T10:00:00+00:00')->assertJsonPath('data.assessment.status', 'unassessed')
            ->assertJsonPath('data.snapshot.participants.0.pseudonym', 'Titulaire historique')->assertJsonMissingPath('data.organization_id')->json('data');
        $s['starter']->forceFill(['pseudonym' => 'Renommé ensuite'])->save();
        $changed = $this->postJson($s['path'].'/substitutions', ['version' => $assigned['match_version'], 'out_person_id' => $s['starter']->id,
            'in_person_id' => $s['sub']->id, 'reason' => 'Remplacement confirmé avant le départ.'])->assertCreated()
            ->assertJsonPath('data.number', 2)->assertJsonPath('data.snapshot.participants.0.slot_key', 'substitute')
            ->assertJsonPath('data.snapshot.participants.1.slot_key', 'starter')->assertJsonPath('data.snapshot.participants.0.pseudonym', 'Titulaire historique')->json('data');
        $this->assertNotSame($assigned['match_version'], $changed['match_version']);
        $this->assertDatabaseCount('match_lineup_revisions', 2);
        $this->assertDatabaseCount('organization_audit_events', 3);
        $this->assertNotSame('Titulaire historique', DB::table('match_lineup_revisions')->value('snapshot'));
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['path'].'?per_page=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.number', 2);
        $this->getJson($s['path'].'?page=2&per_page=1')->assertOk()->assertJsonPath('data.0.snapshot.participants.0.slot_key', 'starter');
    }

    #[Group('tenant-isolation')]
    public function test_insufficient_permissions_unknown_fields_and_foreign_sources_write_nothing(): void
    {
        $s = $this->matchLineupScenario();
        $body = ['version' => $s['match']->version, 'lineup_id' => $s['lineup']->id, 'reason' => 'Sélection retenue avant la rencontre.'];
        $this->postJson($s['path'], [...$body, 'snapshot' => []])->assertUnprocessable()->assertJsonValidationErrors('snapshot');
        $this->postJson($s['path'], [...$body, 'reason' => ' court '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $foreign = TeamLineup::factory()->create();
        $this->postJson($s['path'], [...$body, 'lineup_id' => $foreign->id])->assertNotFound();
        $this->getJson($s['path'].'?per_page=101')->assertUnprocessable();
        $this->actingAsWithMfa($s['reader']);
        $this->postJson($s['path'], $body)->assertForbidden();
        $this->getJson($s['path'].'/choices')->assertForbidden();
        $this->getJson($s['path'].'?management=1')->assertForbidden();
        $this->assertDatabaseCount('match_lineup_revisions', 0);
    }

    public function test_start_requires_team_lineup_but_individual_matches_remain_valid(): void
    {
        $s = $this->matchLineupScenario();
        $root = $this->matchRoot($s['org']).'/'.$s['match']->id;
        $confirmed = $this->postJson($root.'/transitions', ['version' => $s['match']->version, 'status' => 'confirmed', 'reason' => 'Confirmation de la rencontre pilote.'])->assertOk()->json('data');
        $this->postJson($root.'/transitions', ['version' => $confirmed['version'], 'status' => 'in_progress', 'reason' => 'Démarrage de la rencontre pilote.'])
            ->assertConflict()->assertJsonPath('code', 'MATCH_LINEUP_REQUIRED');
        $this->assertDatabaseHas('matches', ['id' => $s['match']->id, 'version' => $confirmed['version'], 'status' => 'confirmed']);
        $assigned = $this->postJson($s['path'], ['version' => $confirmed['version'], 'lineup_id' => $s['lineup']->id, 'reason' => 'Sélection retenue avant la rencontre.'])->assertCreated()->json('data');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $started = $this->postJson($root.'/transitions', ['version' => $assigned['match_version'], 'status' => 'in_progress', 'reason' => 'Démarrage de la rencontre pilote.'])->assertOk()->json('data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, array_filter($queries, fn (array $q): bool => str_starts_with(strtolower($q['query']), 'select') && str_contains($q['query'], 'from "match_lineup_revisions"')));
        $this->postJson($s['path'], ['version' => $started['version'], 'lineup_id' => $s['lineup']->id, 'reason' => 'Changement de sélection refusé ici.'])->assertConflict()->assertJsonPath('code', 'MATCH_LINEUP_FROZEN');
        $this->travelTo('2026-10-19 10:30:00');
        $this->actingAsWithMfa($s['writer']);
        $changed = $this->postJson($s['path'].'/substitutions', ['version' => $started['version'], 'out_person_id' => $s['starter']->id, 'in_person_id' => $s['sub']->id,
            'reason' => 'Remplacement pendant la rencontre.'])->assertCreated()->assertJsonPath('data.phase', 'in_progress')
            ->assertJsonPath('data.assessment.effective_at', '2026-10-19T10:30:00+00:00')->json('data');
        $ended = $this->postJson($root.'/transitions', ['version' => $changed['match_version'], 'status' => 'awaiting_validation', 'reason' => 'Rencontre terminée en attente de résultat.'])->assertOk()->json('data');
        $this->postJson($s['path'].'/substitutions', ['version' => $ended['version'], 'out_person_id' => $s['sub']->id, 'in_person_id' => $s['starter']->id,
            'reason' => 'Tentative après la rencontre terminée.'])->assertConflict()->assertJsonPath('code', 'MATCH_LINEUP_FROZEN');
    }

    public function test_stale_version_noop_and_invalid_substitutions_are_refused(): void
    {
        $s = $this->matchLineupScenario();
        $assigned = $this->postJson($s['path'], ['version' => $s['match']->version, 'lineup_id' => $s['lineup']->id, 'reason' => 'Sélection retenue avant la rencontre.'])->assertCreated()->json('data');
        $this->postJson($s['path'], ['version' => $s['match']->version, 'lineup_id' => $s['lineup']->id, 'reason' => 'Ancienne version de la sélection.'])->assertConflict()->assertJsonPath('code', 'MATCH_VERSION_STALE');
        $this->postJson($s['path'], ['version' => $assigned['match_version'], 'lineup_id' => $s['lineup']->id, 'reason' => 'Même composition déjà conservée.'])->assertConflict()->assertJsonPath('code', 'MATCH_LINEUP_UNCHANGED');
        $this->postJson($s['path'].'/substitutions', ['version' => $assigned['match_version'], 'out_person_id' => $s['sub']->id, 'in_person_id' => $s['starter']->id,
            'reason' => 'Les rôles sont inversés dans la requête.'])->assertUnprocessable()->assertJsonValidationErrors('in_person_id');
        $this->postJson($s['path'].'/substitutions', ['version' => $assigned['match_version'], 'out_person_id' => $s['starter']->id, 'in_person_id' => $s['starter']->id,
            'reason' => 'Une personne ne se remplace pas elle-même.'])->assertUnprocessable()->assertJsonPath('errors.in_person_id.0', __('match_lineups.substitution'));
        $this->postJson($s['path'].'/substitutions', ['version' => $assigned['match_version'], 'out_person_id' => $s['starter']->id, 'in_person_id' => (string) Str::uuid(),
            'reason' => 'Personne en dehors de la sélection.'])->assertUnprocessable();
        $this->patchJson($this->matchRoot($s['org']).'/'.$s['match']->id, ['version' => $assigned['match_version'], 'participants' => [['kind' => 'team', 'source_id' => $s['team']->id], ['kind' => 'person', 'source_id' => $s['sub']->id]]])
            ->assertConflict()->assertJsonPath('code', 'MATCH_LINEUP_PARTICIPANTS_RETAINED');
        $this->assertDatabaseCount('match_lineup_revisions', 1);
    }

    public function test_roster_change_blocks_new_writes_without_rewriting_history(): void
    {
        $s = $this->matchLineupScenario();
        $assigned = $this->postJson($s['path'], ['version' => $s['match']->version, 'lineup_id' => $s['lineup']->id, 'reason' => 'Sélection retenue avant la rencontre.'])->assertCreated()->json('data');
        $s['period']->forceFill(['ends_at' => '2026-10-18 12:00:00', 'version' => (string) Str::uuid()])->save();
        $this->postJson($s['path'].'/substitutions', ['version' => $assigned['match_version'], 'out_person_id' => $s['starter']->id, 'in_person_id' => $s['sub']->id,
            'reason' => 'Un joueur a quitté le roster retenu.'])->assertConflict()->assertJsonPath('code', 'MATCH_LINEUP_NOT_ELIGIBLE');
        $this->getJson($s['path'])->assertOk()->assertJsonPath('data.0.snapshot.participants.0.pseudonym', 'Titulaire historique');
        $this->assertDatabaseCount('match_lineup_revisions', 1);
    }

    public function test_audit_failure_rolls_back_match_version_and_revision(): void
    {
        $s = $this->matchLineupScenario();
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Journal indisponible'));
        $this->postJson($s['path'], ['version' => $s['match']->version, 'lineup_id' => $s['lineup']->id, 'reason' => 'Sélection retenue avant la rencontre.'])->assertStatus(500);
        $this->assertDatabaseCount('match_lineup_revisions', 0);
        $this->assertDatabaseHas('matches', ['id' => $s['match']->id, 'version' => $s['match']->version]);
    }

    public function test_finalized_rules_are_rechecked_at_match_date_and_proof_is_retained(): void
    {
        $s = $this->matchLineupScenario();
        OrganizationRole::where('organization_id', $s['org']->id)->get()->firstOrFail(fn ($r) => in_array('sports.write', $r->permissions, true))->forceFill(['permissions' => ['sports.read', 'sports.write', 'teams.manage']])->save();
        $draft = TeamLineup::factory()->create(['team_id' => $s['team']->id, 'effective_at' => '2026-10-19 10:00:00']);
        foreach ($s['lineup']->entries as $entry) {
            TeamLineupEntry::factory()->create(['lineup_id' => $draft->id, 'membership_id' => $entry->membership_id, 'membership_role_key' => $entry->membership_role_key, 'slot_key' => $entry->slot_key, 'position' => $entry->position]);
        }
        $rules = TeamEligibilityRuleset::factory()->create(['organization_id' => $s['org']->id, 'game_id' => $s['team']->game_id]);
        $eligibility = app(LineupEligibilityService::class);
        $bound = $eligibility->bind($this->personContext($s['org'], $s['writer']), $s['team']->id, $draft->id, ['version' => $draft->version, 'ruleset_id' => $rules->id, 'ruleset_version' => $rules->version]);
        app(TeamLineupService::class)->change($this->personContext($s['org'], $s['writer']), $s['team']->id, $draft->id,
            ['action' => 'finalize', 'version' => $bound->version, 'team_version' => $s['team']->version]);
        $this->postJson($s['path'], ['version' => $s['match']->version, 'lineup_id' => $draft->id, 'reason' => 'Sélection éligible selon le règlement.'])->assertCreated()->assertJsonPath('data.assessment.status', 'eligible')->assertJsonPath('data.snapshot.ruleset_id', $rules->id);
        $this->assertNotEmpty(MatchLineupRevision::firstOrFail()->assessment['checks']);
        $rules->forceFill(['status' => 'archived'])->save();
        $version = $s['match']->refresh()->version;
        $this->postJson($s['path'].'/substitutions', ['version' => $version, 'out_person_id' => $s['starter']->id, 'in_person_id' => $s['sub']->id,
            'reason' => 'Règlement archivé après cette sélection.'])->assertConflict()->assertJsonPath('code', 'MATCH_LINEUP_NOT_ELIGIBLE');
        $this->assertDatabaseCount('match_lineup_revisions', 1);
    }

    public function test_missing_private_eligibility_facts_are_never_treated_as_eligible(): void
    {
        $s = $this->matchLineupScenario();
        $role = OrganizationRole::where('organization_id', $s['org']->id)->get()->firstOrFail(fn ($r) => in_array('sports.write', $r->permissions, true));
        $role->forceFill(['permissions' => ['sports.read', 'sports.write', 'teams.manage', 'administrative.read']])->save();
        $s['starter']->forceFill(['birth_date' => '2000-01-01'])->save();
        $s['sub']->forceFill(['birth_date' => '2000-02-01'])->save();
        $draft = TeamLineup::factory()->create(['team_id' => $s['team']->id, 'effective_at' => '2026-10-19 10:00:00']);
        foreach ($s['lineup']->entries as $entry) {
            TeamLineupEntry::factory()->create(['lineup_id' => $draft->id, 'membership_id' => $entry->membership_id, 'membership_role_key' => $entry->membership_role_key, 'slot_key' => $entry->slot_key, 'position' => $entry->position]);
        }
        $rules = TeamEligibilityRuleset::factory()->create(['organization_id' => $s['org']->id, 'game_id' => $s['team']->game_id, 'min_age' => 18]);
        $bound = app(LineupEligibilityService::class)->bind($this->personContext($s['org'], $s['writer']), $s['team']->id, $draft->id, ['version' => $draft->version, 'ruleset_id' => $rules->id, 'ruleset_version' => $rules->version]);
        app(TeamLineupService::class)->change($this->personContext($s['org'], $s['writer']), $s['team']->id, $draft->id,
            ['action' => 'finalize', 'version' => $bound->version, 'team_version' => $s['team']->version]);
        $role->forceFill(['permissions' => ['sports.read', 'sports.write']])->save();
        $this->postJson($s['path'], ['version' => $s['match']->version, 'lineup_id' => $draft->id, 'reason' => 'Évaluation privée désormais inaccessible.'])->assertConflict()
            ->assertJsonPath('code', 'MATCH_LINEUP_NOT_ELIGIBLE')->assertJsonMissingPath('data.birth_date');
        $this->assertDatabaseCount('match_lineup_revisions', 0);
    }

    public function test_one_person_cannot_play_for_two_participants_at_start(): void
    {
        $s = $this->matchLineupScenario();
        $root = $this->matchRoot($s['org']).'/'.$s['match']->id;
        $updated = $this->patchJson($root, ['version' => $s['match']->version, 'participants' => [['kind' => 'team', 'source_id' => $s['team']->id], ['kind' => 'person', 'source_id' => $s['sub']->id]]])->assertOk()->json('data');
        $path = $root.'/participants/'.$updated['participants'][0]['id'].'/lineups';
        $assigned = $this->postJson($path, ['version' => $updated['version'], 'lineup_id' => $s['lineup']->id, 'reason' => 'Sélection retenue avant la rencontre.'])->assertCreated()->json('data');
        $confirmed = $this->postJson($root.'/transitions', ['version' => $assigned['match_version'], 'status' => 'confirmed', 'reason' => 'Confirmation du dossier préparatoire.'])->assertOk()->json('data');
        $this->postJson($root.'/transitions', ['version' => $confirmed['version'], 'status' => 'in_progress', 'reason' => 'Vérification des camps au démarrage.'])->assertConflict()->assertJsonPath('code', 'MATCH_LINEUP_DUPLICATE_PERSON');
        $this->assertDatabaseHas('matches', ['id' => $s['match']->id, 'status' => 'confirmed', 'version' => $confirmed['version']]);
    }

    public function test_shared_assignment_requires_publish_permission_and_explicit_confirmation(): void
    {
        $s = $this->matchLineupScenario();
        $role = OrganizationRole::where('organization_id', $s['org']->id)->get()->firstOrFail(fn ($r) => in_array('sports.write', $r->permissions, true));
        $role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.publish']])->save();
        $shared = $this->patchJson($this->matchRoot($s['org']).'/'.$s['match']->id, ['version' => $s['match']->version, 'visibility' => 'organization', 'confirm_sharing' => true])->assertOk()->json('data');
        $body = ['version' => $shared['version'], 'lineup_id' => $s['lineup']->id, 'reason' => 'Sélection partagée dans la rencontre.'];
        $this->postJson($s['path'], $body)->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');
        $role->forceFill(['permissions' => ['sports.read', 'sports.write']])->save();
        $this->postJson($s['path'], [...$body, 'confirm_sharing' => true])->assertForbidden();
        $this->assertDatabaseCount('match_lineup_revisions', 0);
        $role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.publish']])->save();
        $this->postJson($s['path'], [...$body, 'confirm_sharing' => true])->assertCreated();
        $this->assertDatabaseCount('match_lineup_revisions', 1);
    }

    public function test_draft_or_non_match_lineup_cannot_be_selected(): void
    {
        $s = $this->matchLineupScenario();
        $draft = TeamLineup::factory()->create(['team_id' => $s['team']->id]);
        $body = ['version' => $s['match']->version, 'lineup_id' => $draft->id, 'reason' => 'Composition encore en préparation.'];
        $this->postJson($s['path'], $body)->assertConflict()->assertJsonPath('code', 'TEAM_LINEUP_NOT_FINALIZED');
        $registration = TeamLineup::factory()->create(['team_id' => $s['team']->id, 'purpose' => 'registration']);
        $this->postJson($s['path'], [...$body, 'lineup_id' => $registration->id])->assertConflict()->assertJsonPath('code', 'MATCH_LINEUP_SOURCE_INVALID');
        $this->assertDatabaseCount('match_lineup_revisions', 0);
    }

    public function test_guest_and_unrelated_reader_cannot_see_private_lineups(): void
    {
        $s = $this->matchLineupScenario();
        $unrelated = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $unrelated, ['sports.read']);
        $this->actingAsWithMfa($unrelated);
        $this->getJson($this->matchRoot($s['org']))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->matchRoot($s['org']).'/'.$s['match']->id)->assertNotFound();
        $this->getJson($this->matchRoot($s['org']).'/'.$s['match']->id.'/history')->assertNotFound();
        $this->getJson($s['path'])->assertNotFound();
        auth()->forgetGuards();
        $this->getJson($s['path'])->assertUnauthorized();
    }
}
