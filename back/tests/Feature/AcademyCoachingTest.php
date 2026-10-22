<?php

namespace Tests\Feature;

use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesAcademyCoaching;
use Tests\Fixtures\ProvidesAcademySessions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class AcademyCoachingTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesAcademyCoaching, ProvidesAcademySessions, ProvidesPeopleAccess;

    public function test_individual_and_collective_objectives_are_encrypted_versioned_and_audited(): void
    {
        $s = $this->coachingScenario();
        $response = $this->postJson($s['path'], $s['body'])->assertCreated()->assertJsonPath('data.scope', 'individual')
            ->assertJsonPath('data.responsible_label', 'Mentor pilote')->assertJsonPath('notification.message', 'Le suivi pédagogique a été enregistré.');
        $this->assertStringNotContainsString('Objectif tactique', DB::table('academy_objectives')->value('title'));
        $this->assertStringNotContainsString('placements', DB::table('academy_objectives')->value('description'));
        $this->postJson($s['path'], [...$s['body'], 'target_enrollment_id' => null])->assertCreated()->assertJsonPath('data.scope', 'collective');
        $id = $response->json('data.id');
        $this->patchJson($s['path'].'/'.$id, ['version' => $response->json('data.version'), 'title' => 'Objectif corrigé', 'change_reason' => 'Correction du libellé pédagogique.'])->assertOk();
        $this->patchJson($s['path'].'/'.$id, ['version' => $response->json('data.version'), 'title' => 'Écrasement', 'change_reason' => 'Commande concurrente périmée.'])->assertConflict();
        $this->assertDatabaseCount('academy_objectives', 2);
        $this->assertDatabaseCount('academy_coaching_revisions', 3);
        $audit = json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('pédagogique', $audit);
        $this->assertStringNotContainsString('placements', $audit);
    }

    public function test_shared_feedback_and_justified_notes_use_separate_immutable_audiences(): void
    {
        $s = $this->coachingScenario();
        $objective = $this->coachingObjective($s);
        $base = $s['path'].'/'.$objective->id;
        $shared = $this->postJson($base.'/feedback', ['content' => 'Retour partagé utile.'])->assertCreated()->assertJsonMissingPath('data.justification');
        $note = $this->postJson($base.'/notes', ['content' => 'Observation interne privée.', 'justification' => 'Préparer le prochain entretien.'])->assertCreated();
        $this->postJson($base.'/notes', ['content' => 'Sans justification'])->assertUnprocessable()->assertJsonValidationErrors('justification');
        $this->patchJson($base.'/notes/'.$note->json('data.id'), ['version' => $note->json('data.version'), 'audience' => 'shared', 'change_reason' => 'Tentative de publication interne.'])->assertUnprocessable();
        $this->patchJson($base.'/feedback/'.$note->json('data.id'), ['version' => $note->json('data.version'), 'content' => 'Fuite', 'change_reason' => 'Mauvais chemin de ressource.'])->assertNotFound();
        $this->getJson($base.'/history')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.snapshot');
        $this->assertStringNotContainsString('Observation interne', DB::table('academy_feedback')->where('audience', 'internal')->value('content'));
        $this->assertStringNotContainsString('Préparer', DB::table('academy_feedback')->where('audience', 'internal')->value('justification'));
        $this->assertStringNotContainsString('Retour partagé', DB::table('academy_coaching_revisions')->value('snapshot'));
        $this->assertNotSame($shared->json('data.id'), $note->json('data.id'));
    }

    public function test_feedback_can_be_corrected_and_withdrawn_with_history_but_cannot_be_reopened(): void
    {
        $s = $this->coachingScenario();
        $objective = $this->coachingObjective($s);
        $base = $s['path'].'/'.$objective->id.'/feedback';
        $created = $this->postJson($base, ['content' => 'Premier retour'])->assertCreated();
        $path = $base.'/'.$created->json('data.id');
        $this->patchJson($path, ['version' => $created->json('data.version'), 'content' => 'Correction'])->assertUnprocessable()->assertJsonValidationErrors('change_reason');
        $changed = $this->patchJson($path, ['version' => $created->json('data.version'), 'content' => 'Retour corrigé', 'change_reason' => 'Correction après échange.'])->assertOk();
        $withdrawn = $this->patchJson($path, ['version' => $changed->json('data.version'), 'status' => 'withdrawn', 'change_reason' => 'Retrait après vérification.'])->assertOk();
        $this->patchJson($path, ['version' => $withdrawn->json('data.version'), 'status' => 'active', 'change_reason' => 'Réouverture non autorisée.'])->assertConflict();
        $this->assertDatabaseCount('academy_coaching_revisions', 3);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidObjectives(): array
    {
        return ['blank title' => [['title' => '  ']], 'deadline outside cycle' => [['due_on' => '2027-01-01']],
            'unknown payload key' => [['organization_id' => 'spoof']], 'bad status' => [['status' => 'completed']],
            'missing explicit audience' => [['target_enrollment_id' => 'not-uuid']]];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidObjectives')]
    public function test_invalid_objective_has_no_side_effect(array $changes): void
    {
        $s = $this->coachingScenario();
        $response = $this->postJson($s['path'], [...$s['body'], ...$changes]);
        if (isset($changes['due_on'])) {
            $response->assertConflict();
        } else {
            $response->assertUnprocessable();
        }
        $this->assertDatabaseCount('academy_objectives', 0);
        $this->assertDatabaseCount('academy_coaching_revisions', 0);
    }

    public function test_scope_and_terminal_state_cannot_be_repurposed(): void
    {
        $s = $this->coachingScenario();
        $created = $this->postJson($s['path'], $s['body'])->assertCreated();
        $path = $s['path'].'/'.$created->json('data.id');
        $this->patchJson($path, ['version' => $created->json('data.version'), 'target_enrollment_id' => null, 'change_reason' => 'Élargissement du destinataire.'])->assertUnprocessable();
        $closed = $this->patchJson($path, ['version' => $created->json('data.version'), 'status' => 'completed', 'change_reason' => 'Objectif atteint et confirmé.'])->assertOk();
        $this->patchJson($path, ['version' => $closed->json('data.version'), 'status' => 'active', 'change_reason' => 'Réouverture de l’objectif.'])->assertConflict();
    }

    public function test_cross_tenant_cycle_and_enrollment_are_not_assignable(): void
    {
        $s = $this->coachingScenario();
        $foreign = AcademyEnrollment::factory()->create();
        $this->postJson($s['path'], [...$s['body'], 'target_enrollment_id' => $foreign->id])->assertNotFound();
        $other = AcademyCycle::factory()->create(['cohort_id' => $s['cohort']->id]);
        $wrongCycle = AcademyEnrollment::factory()->create(['cycle_id' => $other->id]);
        $this->postJson($s['path'], [...$s['body'], 'target_enrollment_id' => $wrongCycle->id])->assertNotFound();
        $this->assertDatabaseCount('academy_objectives', 0);
    }

    public function test_revoked_mentor_and_closed_cycle_block_commands_without_creating_permissions(): void
    {
        $s = $this->coachingScenario();
        OrganizationMembership::query()->where('organization_id', $s['org']->id)->where('user_id', $s['mentor_user']->id)->update(['status' => 'suspended']);
        $this->postJson($s['path'], $s['body'])->assertConflict();
        $this->assertDatabaseCount('academy_objectives', 0);
        $this->assertDatabaseMissing('organization_role_assignments', ['user_id' => $s['mentor_user']->id]);
    }

    public function test_lists_and_history_are_paginated_and_unknown_filters_rejected(): void
    {
        $s = $this->coachingScenario();
        foreach (range(1, 3) as $n) {
            $this->postJson($s['path'], [...$s['body'], 'title' => 'Objectif '.$n])->assertCreated();
        }
        $this->getJson($s['path'].'?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson($s['path'].'?per_page=101')->assertUnprocessable();
        $this->getJson($s['path'].'?person_id='.Str::uuid())->assertUnprocessable();
        $s['cycle']->forceFill(['status' => 'archived'])->save();
        $this->postJson($s['path'], $s['body'])->assertConflict();
    }

    public function test_closing_only_command_remains_possible_after_enrollment_withdrawal(): void
    {
        $s = $this->coachingScenario();
        $objective = $this->coachingObjective($s);
        $s['student']->forceFill(['status' => 'withdrawn'])->save();
        $s['mentor']->forceFill(['status' => 'withdrawn'])->save();
        $path = $s['path'].'/'.$objective->id;
        $this->patchJson($path, ['version' => $objective->version, 'status' => 'cancelled', 'title' => 'Modification masquée', 'change_reason' => 'Annulation après départ.'])->assertUnprocessable();
        $this->patchJson($path, ['version' => $objective->version, 'status' => 'cancelled', 'change_reason' => 'Annulation après départ.'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseCount('academy_coaching_revisions', 1);
    }

    public function test_guest_and_owner_have_no_implicit_access(): void
    {
        $org = Organization::factory()->create();
        $path = '/api/v1/tenants/'.$org->id.'/academy/my-objectives';
        $this->getJson($path)->assertUnauthorized();
        $this->actingAsWithMfa(User::query()->findOrFail($org->owner_user_id))->getJson($path)->assertForbidden();
    }
}
