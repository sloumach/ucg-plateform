<?php

namespace Tests\Feature;

use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\AcademyObjective;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_foreign_objective_ids_are_hidden_even_for_an_authorized_staff_actor(): void
    {
        $s = $this->coachingScenario();
        $foreign = AcademyObjective::factory()->create();
        $this->getJson($s['path'].'/'.$foreign->id.'/feedback')->assertNotFound();
        $this->postJson($s['path'].'/'.$foreign->id.'/notes', ['content' => 'Note hors tenant', 'justification' => 'Tentative hors organisation.'])->assertNotFound();
        $this->assertDatabaseCount('academy_feedback', 0);
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
