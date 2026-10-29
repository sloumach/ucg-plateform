<?php

namespace Tests\Pilot;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchResults;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PilotWeekScenarioTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMatchResults, ProvidesPeopleAccess;

    public function test_fictitious_week_keeps_one_team_calendar_contract_and_validated_result_consistent(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertFalse(app()->configurationIsCached());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', config('database.default'));
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        [$org, $writer, $reader, $person, $opponent, $game] = $this->matchScenario();
        $this->permissions($org, $writer, ['sports.read', 'sports.write', 'teams.manage', 'calendar.read', 'calendar.write', 'calendar.publish',
            'administrative.read', 'administrative.write', 'reporting.direction']);
        $this->permissions($org, $reader, ['sports.read', 'sports.validate', 'calendar.read']);
        $administrator = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $administrator, ['administrative.read', 'administrative.write', 'administrative.validate']);
        $foreign = Person::factory()->create(['pseudonym' => 'FICTIF autre organisation']);
        $foreignBefore = $foreign->refresh()->getRawOriginal();
        $root = '/api/v1/tenants/'.$org->id;
        $days = [];

        $this->day(19, $writer);
        $days[] = now()->toDateString();
        $team = $this->postJson($root.'/teams', ['game_id' => $game->id, 'slug' => 'fictif-pilote', 'season' => '2026', 'name' => 'FICTIF Pilote', 'kind' => 'first', 'status' => 'active'])
            ->assertCreated()->json('data');
        $membership = $this->postJson($root.'/teams/'.$team['id'].'/memberships', ['person_id' => $person->id, 'role_key' => 'player', 'starts_at' => '2026-10-19T08:00:00+00:00', 'reason_key' => 'initial'])->assertCreated()->json('data');
        for ($day = 19; $day <= 25; $day++) {
            $this->postJson($root.'/calendar/activities', ['title' => 'FICTIF activité '.$day, 'kind' => 'training',
                'starts_at' => '2026-10-'.$day.'T14:00:00+00:00', 'ends_at' => '2026-10-'.$day.'T15:00:00+00:00',
                'origin_timezone' => 'UTC', 'visibility' => 'organization', 'confirm_sharing' => true, 'participant_ids' => [$person->id]])->assertCreated();
        }
        $contract = $this->postJson($root.'/contracts', ['reference' => 'fictif-rel001', 'type' => 'player', 'title' => 'FICTIF contrat autorisé',
            'responsible_person_id' => $person->id, 'starts_on' => '2026-10-19', 'ends_on' => '2026-12-31',
            'parties' => [['kind' => 'person', 'person_id' => $person->id, 'name' => 'FICTIF partie']]])->assertCreated()->json('data');
        $contract = $this->transitionContract($root, $contract, 'in_review');

        $this->day(20, $administrator);
        $days[] = now()->toDateString();
        $contract = $this->transitionContract($root, $contract, 'approved');
        $this->assertSame($administrator->id, $contract['approved_by']);
        $contract = $this->transitionContract($root, $contract, 'sent');
        $contract = $this->transitionContract($root, $contract, 'signed', ['signed_on' => '2026-10-20', 'signature_reference' => 'FICTIF reçu hors application', 'confirm_signature_received' => true]);
        $contract = $this->transitionContract($root, $contract, 'active');
        $this->assertTrue($contract['in_force']);
        $this->assertSame('not_assessed', $contract['sporting_eligibility']);

        $this->day(21, $writer);
        $days[] = now()->toDateString();
        $record = $this->postJson($root.'/matches', [...$this->matchPayload($game, $person, $opponent), 'title' => 'FICTIF match semaine', 'format' => 'bo1',
            'starts_at' => '2026-10-22T10:00:00+00:00', 'ends_at' => '2026-10-22T12:00:00+00:00',
            'participants' => [['kind' => 'team', 'source_id' => $team['id']], ['kind' => 'person', 'source_id' => $opponent->id]]])->assertCreated()->json('data');
        $matchRoot = $root.'/matches/'.$record['id'];
        $team = $this->getJson($root.'/teams/'.$team['id'])->assertOk()->json('data');
        $lineupRoot = $root.'/teams/'.$team['id'].'/lineups';
        $lineup = $this->postJson($lineupRoot, ['team_version' => $team['version'], 'purpose' => 'match', 'label' => 'FICTIF composition du jeudi',
            'effective_at' => '2026-10-22T10:00:00+00:00', 'entries' => [['membership_id' => $membership['id'], 'membership_version' => $membership['version'], 'slot_key' => 'starter']]])->assertCreated()->json('data');
        $lineup = $this->patchJson($lineupRoot.'/'.$lineup['id'], ['action' => 'finalize', 'version' => $lineup['version'], 'team_version' => $team['version']])->assertOk()->json('data');
        $assigned = $this->postJson($matchRoot.'/participants/'.$record['participants'][0]['id'].'/lineups', ['version' => $record['version'], 'lineup_id' => $lineup['id'], 'reason' => 'FICTIF sélection vérifiée avant rencontre.'])->assertCreated()->json('data');
        $record = $this->postJson($matchRoot.'/transitions', ['version' => $assigned['match_version'], 'status' => 'confirmed', 'reason' => 'FICTIF transition sportive vérifiée.'])->assertOk()->json('data');

        $this->day(22, $writer);
        $days[] = now()->toDateString();
        $record = $this->postJson($matchRoot.'/transitions', ['version' => $record['version'], 'status' => 'in_progress', 'reason' => 'FICTIF début de la rencontre.'])->assertOk()->json('data');
        $rules = $this->postJson($matchRoot.'/score-rules', ['version' => $record['version'], 'game_limit' => 1, 'allow_draw' => false, 'reason' => 'FICTIF règles convenues avant saisie.'])->assertCreated()->json('data');
        $record['version'] = $rules['match_version'];
        $record['version'] = $this->postJson($matchRoot.'/games', $this->scorePayload(['record' => $record]))->assertCreated()->json('data.match_version');
        $result = $this->postJson($matchRoot.'/results', $this->resultPayload(['record' => $record]))->assertCreated()->json('data');
        $this->postJson($matchRoot.'/results/approvals', $this->decisionPayload($result))->assertForbidden();

        $this->day(23, $reader);
        $days[] = now()->toDateString();
        $this->getJson($root.'/contracts/'.$contract['id'])->assertForbidden();
        $this->getJson($root.'/people/'.$foreign->id)->assertNotFound();
        $this->postJson($matchRoot.'/results/approvals', $this->decisionPayload($result))->assertOk()->assertJsonPath('data.status', 'validated');
        $this->getJson($root.'/calendar/activities?from_date=2026-10-19&to_date=2026-10-26&timezone=UTC&per_page=25')
            ->assertOk()->assertJsonPath('meta.total', 7)->assertJsonCount(7, 'data');

        $this->day(24, $reader);
        $days[] = now()->toDateString();
        $this->permissions($org, $reader, ['sports.read']);
        $this->getJson($root.'/calendar/activities?from_date=2026-10-19&to_date=2026-10-26')->assertForbidden();
        $this->getJson($matchRoot.'/results')->assertOk()->assertJsonPath('result.status', 'validated');

        $this->day(25, $writer);
        $days[] = now()->toDateString();
        $period = '?view=direction&from=2026-10-19&to=2026-10-25';
        $this->getJson($root.'/dashboards/active_people'.$period)->assertOk()->assertJsonPath('data.total', 2)->assertJsonMissingPath('data.items.0.contact_email');
        $this->getJson($root.'/calendar/activities?from_date=2026-10-19&to_date=2026-10-26&timezone=UTC&per_page=1')->assertOk()->assertJsonPath('meta.total', 7)->assertJsonCount(1, 'data');
        $this->assertSame(['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25'], $days);
        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseCount('team_memberships', 1);
        $this->assertDatabaseCount('calendar_activities', 7);
        $this->assertDatabaseHas('contracts', ['id' => $contract['id'], 'status' => 'active']);
        $this->assertDatabaseHas('matches', ['id' => $record['id'], 'status' => 'validated']);
        $this->assertSame($foreignBefore, $foreign->fresh()?->getRawOriginal());
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function day(int $day, User $actor): void
    {
        $this->travelTo('2026-10-'.$day.' 09:00:00 UTC');
        $this->actingAsWithMfa($actor);
    }

    /** @param list<string> $permissions */
    private function permissions(Organization $org, User $user, array $permissions): void
    {
        $role = OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)->value('role_id');
        OrganizationRole::query()->whereKey($role)->firstOrFail()->forceFill(['permissions' => $permissions, 'privileged' => true])->save();
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function transitionContract(string $root, array $contract, string $status, array $extra = []): array
    {
        return $this->postJson($root.'/contracts/'.$contract['id'].'/transitions', ['version' => $contract['version'], 'status' => $status,
            ...$extra])->assertOk()->json('data');
    }
}
