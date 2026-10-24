<?php

namespace Tests\Feature;

use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\AcademyObjective;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesAcademyCoaching;
use Tests\Fixtures\ProvidesAcademySessions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class AcademyCoachingVisibilityTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesAcademyCoaching, ProvidesAcademySessions, ProvidesPeopleAccess;

    public function test_player_only_sees_own_and_collective_objectives_and_active_shared_feedback(): void
    {
        $s = $this->coachingScenario();
        $own = $this->coachingObjective($s);
        $collective = $this->coachingObjective($s, true);
        $other = AcademyEnrollment::factory()->create(['cycle_id' => $s['cycle']->id]);
        $foreign = $this->coachingObjective([...$s, 'student' => $other]);
        $base = $s['path'].'/'.$own->id;
        $this->postJson($base.'/notes', ['content' => 'SECRET_INTERNE', 'justification' => 'Préparation privée du mentor.'])->assertCreated();
        $shared = $this->postJson($base.'/feedback', ['content' => 'Retour personnel partagé'])->assertCreated();
        $withdrawn = $this->postJson($base.'/feedback', ['content' => 'RETOUR_RETIRE'])->assertCreated();
        $this->patchJson($base.'/feedback/'.$withdrawn->json('data.id'), ['version' => $withdrawn->json('data.version'), 'status' => 'withdrawn', 'change_reason' => 'Retrait du commentaire incorrect.'])->assertOk();
        $player = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $player, ['academy.self.read']);
        Person::query()->whereKey($s['student']->person_id)->firstOrFail()->forceFill(['user_id' => $player->id])->save();
        $this->actingAsWithMfa($player);
        $mine = '/api/v1/tenants/'.$s['org']->id.'/academy/my-objectives';
        $this->getJson($mine.'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonMissingPath('data.0.version')->assertJsonMissingPath('data.0.responsible_enrollment_id');
        $this->getJson($mine.'/'.$own->id.'/feedback')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shared->json('data.id'))
            ->assertJsonMissingPath('data.0.justification')->assertJsonMissingPath('data.0.audience')->assertDontSee('SECRET_INTERNE')->assertDontSee('RETOUR_RETIRE');
        $this->getJson($mine.'/'.$foreign->id.'/feedback')->assertNotFound();
        $this->getJson($mine.'/'.$collective->id.'/feedback')->assertOk();
        $this->getJson($s['path'])->assertForbidden();
        $this->getJson($base.'/notes')->assertForbidden();
        $this->getJson($base.'/history')->assertForbidden();
        $this->postJson($base.'/feedback', ['content' => 'Une écriture'])->assertForbidden();
    }

    public function test_staff_shared_access_does_not_disclose_note_rows_counts_or_history(): void
    {
        $s = $this->coachingScenario();
        $objective = $this->coachingObjective($s);
        $base = $s['path'].'/'.$objective->id;
        $note = $this->postJson($base.'/notes', ['content' => 'Contenu interne masqué', 'justification' => 'Préparer un échange confidentiel.'])->assertCreated();
        $this->postJson($base.'/feedback', ['content' => 'Retour partagé'])->assertCreated();
        $reader = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $reader, ['academy.read', 'academy.manage', 'academy.feedback.manage']);
        $this->actingAsWithMfa($reader);
        $this->getJson($base.'/feedback')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->getJson($base.'/notes')->assertForbidden();
        $this->postJson($base.'/notes', ['content' => 'Note', 'justification' => 'Tentative non autorisée.'])->assertForbidden();
        $this->getJson($base.'/history')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertDontSee($note->json('data.id'));
        $this->getJson('/api/v1/tenants/'.$s['org']->id.'/academy/my-objectives')->assertForbidden();
    }

    public function test_feedback_writer_and_note_writer_do_not_gain_objective_management(): void
    {
        $s = $this->coachingScenario();
        $objective = $this->coachingObjective($s);
        $base = $s['path'].'/'.$objective->id;
        $writer = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $writer, ['academy.read', 'academy.feedback.manage']);
        $this->actingAsWithMfa($writer);
        $this->postJson($base.'/feedback', ['content' => 'Retour du coach autorisé.'])->assertCreated();
        $this->postJson($s['path'], $s['body'])->assertForbidden();
        $this->postJson($base.'/notes', ['content' => 'Note', 'justification' => 'Tentative du coach partagé.'])->assertForbidden();
        $noteWriter = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $noteWriter, ['academy.read', 'academy.notes.manage']);
        $this->actingAsWithMfa($noteWriter);
        $this->postJson($base.'/notes', ['content' => 'Note de travail', 'justification' => 'Préparer le suivi pédagogique.'])->assertCreated();
        $this->postJson($base.'/feedback', ['content' => 'Partage sans autorisation'])->assertForbidden();
        $this->postJson($s['path'], $s['body'])->assertForbidden();
    }

    public function test_self_access_is_rechecked_after_withdrawal_departure_and_membership_revocation(): void
    {
        $s = $this->coachingScenario();
        $objective = $this->coachingObjective($s);
        $player = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $player, ['academy.self.read']);
        Person::query()->whereKey($s['student']->person_id)->firstOrFail()->forceFill(['user_id' => $player->id])->save();
        $this->actingAsWithMfa($player);
        $mine = '/api/v1/tenants/'.$s['org']->id.'/academy/my-objectives';
        $this->getJson($mine)->assertOk()->assertJsonCount(1, 'data');
        $s['student']->forceFill(['status' => 'withdrawn'])->save();
        $this->getJson($mine)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($mine.'/'.$objective->id.'/feedback')->assertNotFound();
        OrganizationMembership::query()->where('organization_id', $s['org']->id)->where('user_id', $player->id)->update(['status' => 'revoked']);
        $this->getJson($mine)->assertNotFound();
    }

    public function test_self_without_link_or_with_a_departed_person_has_an_empty_page(): void
    {
        $s = $this->coachingScenario();
        $this->coachingObjective($s, true);
        $player = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $player, ['academy.self.read']);
        $this->actingAsWithMfa($player);
        $mine = '/api/v1/tenants/'.$s['org']->id.'/academy/my-objectives';
        $this->getJson($mine)->assertOk()->assertJsonCount(0, 'data');
        Person::query()->whereKey($s['student']->person_id)->firstOrFail()->forceFill(['user_id' => $player->id, 'status' => 'former'])->save();
        $this->getJson($mine)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($mine.'?person_id='.$s['student']->person_id)->assertUnprocessable();
    }

    public function test_personal_dates_use_cycle_timezone_before_pagination(): void
    {
        $s = $this->coachingScenario();
        $this->coachingObjective($s, true);
        $this->travelTo('2026-10-22 00:30:00 UTC');
        $player = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $player, ['academy.self.read']);
        Person::query()->whereKey($s['student']->person_id)->firstOrFail()->forceFill(['user_id' => $player->id])->save();
        $s['cycle']->forceFill(['timezone' => 'America/Los_Angeles'])->save();
        $s['student']->forceFill(['status' => 'withdrawn'])->save();
        AcademyEnrollment::factory()->create(['cycle_id' => $s['cycle']->id, 'person_id' => $s['student']->person_id, 'ends_on' => '2026-10-21']);
        $this->actingAsWithMfa($player);
        $mine = '/api/v1/tenants/'.$s['org']->id.'/academy/my-objectives';
        $this->getJson($mine)->assertOk()->assertJsonPath('meta.total', 1);
        $this->travelTo('2026-10-22 08:00:00 UTC');
        $this->getJson($mine)->assertOk()->assertJsonPath('meta.total', 0);
    }

    #[Group('tenant-isolation')]
    public function test_foreign_objective_ids_are_hidden_even_for_an_authorized_staff_actor(): void
    {
        $s = $this->coachingScenario();
        $foreign = AcademyObjective::factory()->create();
        $this->getJson($s['path'].'/'.$foreign->id.'/feedback')->assertNotFound();
        $this->postJson($s['path'].'/'.$foreign->id.'/notes', ['content' => 'Note hors tenant', 'justification' => 'Tentative hors organisation.'])->assertNotFound();
        $this->assertDatabaseCount('academy_feedback', 0);
    }

    #[Group('tenant-isolation')]
    public function test_multi_tenant_coach_cannot_read_or_change_foreign_objectives_and_feedback(): void
    {
        $s = $this->savedCoachingScenario();
        $other = $this->savedCoachingScenario();
        $this->grantPeopleAccess($other['org'], $s['actor'], ['academy.read', 'academy.manage', 'academy.feedback.manage', 'academy.notes.manage']);
        $this->actingAsWithMfa($s['actor']);
        $this->getJson($other['path'])->assertOk()->assertJsonPath('data.0.id', $other['objective']['id']);
        $this->getJson($other['base'].'/notes')->assertOk()->assertJsonPath('data.0.id', $other['note']['id']);
        $before = $this->coachingSnapshot();
        $audits = DB::table('organization_audit_events')->count();
        $foreign = $s['path'].'/'.$other['objective']['id'];

        foreach (['feedback', 'notes', 'history'] as $suffix) {
            $this->getJson($foreign.'/'.$suffix)->assertNotFound()->assertJsonMissingPath('data');
        }
        $this->patchJson($foreign, ['version' => $other['objective']['version'], 'title' => 'Objectif interdit', 'change_reason' => 'Correction sous le mauvais tenant.'])->assertNotFound();
        $this->postJson($foreign.'/feedback', ['content' => 'Retour interdit'])->assertNotFound();
        $this->postJson($foreign.'/notes', ['content' => 'Note interdite', 'justification' => 'Tentative sous le mauvais tenant.'])->assertNotFound();
        $this->patchJson($s['base'].'/feedback/'.$other['feedback']['id'], $this->feedbackCorrection($other['feedback']))->assertNotFound();
        $this->patchJson($s['base'].'/notes/'.$other['note']['id'], $this->feedbackCorrection($other['note']))->assertNotFound();
        $this->postJson($s['path'], [...$s['body'], 'responsible_enrollment_id' => $other['mentor']->id])->assertNotFound();

        $this->assertSame($before, $this->coachingSnapshot());
        $this->assertSame($audits, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('academy_objectives', 2);
        $this->assertDatabaseCount('academy_feedback', 4);
        $this->assertDatabaseCount('academy_coaching_revisions', 6);
    }

    #[Group('tenant-isolation')]
    public function test_feedback_and_objective_ids_cannot_be_reused_under_another_parent_in_the_same_tenant(): void
    {
        $s = $this->savedCoachingScenario();
        $second = $this->postJson($s['path'], [...$s['body'], 'title' => 'Second objectif'])->assertCreated()->json('data');
        $base = $s['path'].'/'.$second['id'];
        $cycle = AcademyCycle::factory()->create(['cohort_id' => $s['cohort']->id]);
        $wrongCycle = str_replace($s['cycle']->id, $cycle->id, $s['path']);
        $before = $this->coachingSnapshot();
        $audits = DB::table('organization_audit_events')->count();

        $this->getJson($base.'/feedback')->assertOk()->assertJsonCount(0, 'data');
        foreach (['feedback', 'notes', 'history'] as $suffix) {
            $this->getJson($wrongCycle.'/'.$s['objective']['id'].'/'.$suffix)->assertNotFound();
        }
        $this->patchJson($wrongCycle.'/'.$s['objective']['id'], ['version' => $s['objective']['version'], 'title' => 'Mauvais cycle', 'change_reason' => 'Correction sous le mauvais cycle.'])->assertNotFound();
        $this->patchJson($base.'/feedback/'.$s['feedback']['id'], $this->feedbackCorrection($s['feedback']))->assertNotFound();
        $this->patchJson($base.'/notes/'.$s['note']['id'], $this->feedbackCorrection($s['note']))->assertNotFound();
        $this->patchJson($s['base'].'/feedback/'.$s['note']['id'], $this->feedbackCorrection($s['note']))->assertNotFound();
        $this->patchJson($s['base'].'/notes/'.$s['feedback']['id'], $this->feedbackCorrection($s['feedback']))->assertNotFound();

        $this->assertSame($before, $this->coachingSnapshot());
        $this->assertSame($audits, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('academy_coaching_revisions', 4);
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership', 404])]
    #[TestWith(['role', 403])]
    #[TestWith(['read', 403])]
    public function test_revocation_blocks_previously_read_coaching_routes_while_beta_remains_usable(string $revocation, int $status): void
    {
        $s = $this->savedCoachingScenario();
        $other = $this->savedCoachingScenario();
        $this->grantPeopleAccess($other['org'], $s['actor'], ['academy.read', 'academy.manage', 'academy.feedback.manage', 'academy.notes.manage']);
        $this->actingAsWithMfa($s['actor']);
        $urls = [$s['path'], $s['base'].'/feedback', $s['base'].'/notes', $s['base'].'/history'];
        foreach ($urls as $url) {
            $this->getJson($url)->assertOk();
        }
        $before = $this->coachingSnapshot($s['org']->id);
        $audits = DB::table('organization_audit_events')->count();
        $assignment = $this->coachingRole($s);
        if ($revocation === 'membership') {
            OrganizationMembership::query()->whereKey($assignment->membership_id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        } elseif ($revocation === 'role') {
            $assignment->forceFill(['status' => 'revoked'])->save();
        } else {
            $assignment->role->forceFill(['permissions' => ['academy.manage', 'academy.feedback.manage', 'academy.notes.manage']])->save();
        }

        foreach ($urls as $url) {
            $this->getJson($url)->assertStatus($status)->assertJsonMissingPath('data')->assertDontSee('SECRET_INTERNE');
        }
        $this->postJson($s['path'], $s['body'])->assertStatus($status);
        $this->patchJson($s['base'], ['version' => $s['objective']['version'], 'title' => 'Interdit', 'change_reason' => 'Correction après révocation.'])->assertStatus($status);
        $this->postJson($s['base'].'/feedback', ['content' => 'Retour interdit'])->assertStatus($status);
        $this->postJson($s['base'].'/notes', ['content' => 'Note interdite', 'justification' => 'Tentative après révocation.'])->assertStatus($status);
        $this->patchJson($s['base'].'/feedback/'.$s['feedback']['id'], $this->feedbackCorrection($s['feedback']))->assertStatus($status);
        $this->patchJson($s['base'].'/notes/'.$s['note']['id'], $this->feedbackCorrection($s['note']))->assertStatus($status);
        $this->assertSame($before, $this->coachingSnapshot($s['org']->id));
        $this->assertSame($audits, DB::table('organization_audit_events')->count());
        $this->getJson($other['base'].'/notes')->assertOk()->assertJsonPath('data.0.id', $other['note']['id']);
        $this->patchJson($other['base'].'/feedback/'.$other['feedback']['id'], $this->feedbackCorrection($other['feedback']))->assertOk()->assertJsonPath('data.content', 'Retour corrigé autorisé.');
        $this->assertSame($before, $this->coachingSnapshot($s['org']->id));
        $this->assertDatabaseCount('academy_coaching_revisions', 7);
    }

    #[Group('tenant-isolation')]
    public function test_removing_note_permission_hides_previously_read_notes_and_their_history_but_preserves_shared_feedback(): void
    {
        $s = $this->savedCoachingScenario();
        $this->getJson($s['base'].'/notes')->assertOk()->assertJsonPath('data.0.content', 'SECRET_INTERNE');
        $this->getJson($s['base'].'/history')->assertOk()->assertJsonPath('meta.total', 3);
        $before = $this->coachingSnapshot();
        $audits = DB::table('organization_audit_events')->count();
        $this->coachingRole($s)->role->forceFill(['permissions' => ['academy.read', 'academy.manage', 'academy.feedback.manage']])->save();

        $this->getJson($s['base'].'/notes')->assertForbidden()->assertJsonMissingPath('data')->assertDontSee('SECRET_INTERNE');
        $this->getJson($s['base'].'/history')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)
            ->assertDontSee($s['note']['id'])->assertJsonMissingPath('data.0.snapshot');
        $this->postJson($s['base'].'/notes', ['content' => 'Interdit', 'justification' => 'Tentative après retrait des droits.'])->assertForbidden();
        $this->patchJson($s['base'].'/notes/'.$s['note']['id'], $this->feedbackCorrection($s['note']))->assertForbidden();
        $this->assertSame($before, $this->coachingSnapshot());
        $this->assertSame($audits, DB::table('organization_audit_events')->count());
        $this->patchJson($s['base'].'/feedback/'.$s['feedback']['id'], $this->feedbackCorrection($s['feedback']))->assertOk()->assertJsonPath('data.content', 'Retour corrigé autorisé.');
        $this->assertDatabaseCount('academy_coaching_revisions', 4);
    }

    #[Group('tenant-isolation')]
    public function test_removing_shared_feedback_management_keeps_reading_and_internal_note_correction(): void
    {
        $s = $this->savedCoachingScenario();
        $this->getJson($s['base'].'/feedback')->assertOk();
        $before = $this->coachingSnapshot();
        $audits = DB::table('organization_audit_events')->count();
        $this->coachingRole($s)->role->forceFill(['permissions' => ['academy.read', 'academy.manage', 'academy.notes.manage']])->save();

        $this->getJson($s['base'].'/feedback')->assertOk()->assertJsonPath('data.0.id', $s['feedback']['id']);
        $this->getJson($s['base'].'/history')->assertOk()->assertJsonPath('meta.total', 3);
        $this->postJson($s['base'].'/feedback', ['content' => 'Retour interdit'])->assertForbidden();
        $this->patchJson($s['base'].'/feedback/'.$s['feedback']['id'], $this->feedbackCorrection($s['feedback']))->assertForbidden();
        $this->assertSame($before, $this->coachingSnapshot());
        $this->assertSame($audits, DB::table('organization_audit_events')->count());
        $this->patchJson($s['base'].'/notes/'.$s['note']['id'], $this->feedbackCorrection($s['note']))->assertOk()->assertJsonPath('data.content', 'Retour corrigé autorisé.');
        $this->getJson($s['base'].'/feedback')->assertOk()->assertJsonPath('data.0.version', $s['feedback']['version']);
        $this->assertDatabaseCount('academy_coaching_revisions', 4);
    }

    #[Group('tenant-isolation')]
    #[TestWith(['withdrawal', 200])]
    #[TestWith(['departure', 200])]
    #[TestWith(['permission', 403])]
    #[TestWith(['membership', 404])]
    public function test_personal_feedback_access_is_rechecked_after_a_successful_read_without_affecting_beta(string $revocation, int $status): void
    {
        $s = $this->savedCoachingScenario();
        $other = $this->savedCoachingScenario();
        $player = User::factory()->withMfa()->create();
        $assignment = $this->grantPeopleAccess($s['org'], $player, ['academy.self.read']);
        $this->grantPeopleAccess($other['org'], $player, ['academy.self.read']);
        foreach ([$s, $other] as $scenario) {
            Person::query()->whereKey($scenario['student']->person_id)->firstOrFail()->forceFill(['user_id' => $player->id])->save();
        }
        $this->actingAsWithMfa($player);
        $mine = '/api/v1/tenants/'.$s['org']->id.'/academy/my-objectives';
        $beta = '/api/v1/tenants/'.$other['org']->id.'/academy/my-objectives';
        $this->getJson($mine)->assertOk()->assertJsonPath('data.0.id', $s['objective']['id']);
        $this->getJson($mine.'/'.$s['objective']['id'].'/feedback')->assertOk()->assertJsonPath('data.0.id', $s['feedback']['id'])->assertDontSee('SECRET_INTERNE');
        $this->getJson($mine.'/'.$other['objective']['id'].'/feedback')->assertNotFound();
        $before = $this->coachingSnapshot();
        $audits = DB::table('organization_audit_events')->count();
        if ($revocation === 'withdrawal') {
            $s['student']->forceFill(['status' => 'withdrawn'])->save();
        } elseif ($revocation === 'departure') {
            Person::query()->whereKey($s['student']->person_id)->firstOrFail()->forceFill(['status' => 'former'])->save();
        } elseif ($revocation === 'permission') {
            $assignment->role->forceFill(['permissions' => []])->save();
        } else {
            OrganizationMembership::query()->whereKey($assignment->membership_id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        }

        if ($status === 200) {
            $this->getJson($mine)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
            $this->getJson($mine.'/'.$s['objective']['id'].'/feedback')->assertNotFound();
        } else {
            $this->getJson($mine)->assertStatus($status)->assertJsonMissingPath('data');
            $this->getJson($mine.'/'.$s['objective']['id'].'/feedback')->assertStatus($status)->assertJsonMissingPath('data');
        }
        $this->getJson($beta)->assertOk()->assertJsonPath('data.0.id', $other['objective']['id']);
        $this->getJson($beta.'/'.$other['objective']['id'].'/feedback')->assertOk()->assertJsonPath('data.0.id', $other['feedback']['id'])
            ->assertJsonMissingPath('data.0.version')->assertDontSee('SECRET_INTERNE');
        $this->assertSame($before, $this->coachingSnapshot());
        $this->assertSame($audits, DB::table('organization_audit_events')->count());
    }

    /** @return array<string, mixed> */
    private function savedCoachingScenario(): array
    {
        $s = $this->coachingScenario();
        $objective = $this->postJson($s['path'], $s['body'])->assertCreated()->json('data');
        $base = $s['path'].'/'.$objective['id'];
        $feedback = $this->postJson($base.'/feedback', ['content' => 'Retour partagé autorisé.'])->assertCreated()->json('data');
        $note = $this->postJson($base.'/notes', ['content' => 'SECRET_INTERNE', 'justification' => 'Préparation confidentielle du mentor.'])->assertCreated()->json('data');

        return [...$s, 'objective' => $objective, 'base' => $base, 'feedback' => $feedback, 'note' => $note];
    }

    /** @param array<string, mixed> $feedback
     * @return array<string, string> */
    private function feedbackCorrection(array $feedback): array
    {
        return ['version' => $feedback['version'], 'content' => 'Retour corrigé autorisé.', 'change_reason' => 'Correction après vérification du suivi.'];
    }

    /** @param array<string, mixed> $s */
    private function coachingRole(array $s): OrganizationRoleAssignment
    {
        return OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['actor']->id)->firstOrFail();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function coachingSnapshot(?string $organization = null): array
    {
        $snapshot = [];
        foreach (['academy_objectives', 'academy_feedback', 'academy_coaching_revisions'] as $table) {
            $snapshot[$table] = array_values(DB::table($table)->when($organization !== null, fn ($query) => $query->where('organization_id', $organization))
                ->orderBy('id')->get()->map(fn (object $row): array => get_object_vars($row))->all());
        }

        return $snapshot;
    }

    public function test_staff_page_batches_person_labels(): void
    {
        $s = $this->coachingScenario();
        foreach (range(1, 3) as $n) {
            $this->coachingObjective($s);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson($s['path'].'?per_page=3')->assertOk()->assertJsonCount(3, 'data');
            $queries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'from "people"'));
            $this->assertCount(1, $queries);
        } finally {
            DB::disableQueryLog();
        }
    }
}
