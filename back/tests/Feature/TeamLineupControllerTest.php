<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Teams\Application\Services\TeamLineupService;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesLineups;
use Tests\TestCase;

class TeamLineupControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesLineups;

    public function test_guest_is_401_and_reader_cannot_write_compositions(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor(['sports.read']);
        $this->getJson($this->lineupPath($team))->assertUnauthorized();
        $this->actingAsWithMfa($user);
        $this->getJson($this->lineupPath($team))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->lineupPath($team), $this->lineupPayload($team, $period))->assertForbidden();
        $this->assertDatabaseCount('team_lineups', 0);
    }

    public function test_create_finalize_and_read_return_private_typed_snapshot_with_author(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $created = $this->postJson($this->lineupPath($team), $this->lineupPayload($team, $period))->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.entries.0.person_id', $period->person_id)->assertJsonPath('notification.type', 'success')->assertJsonMissingPath('data.organization_id')
            ->assertJsonMissingPath('data.entries.0.contact_email')->assertJsonMissingPath('data.entries.0.user_id');
        $id = $created->json('data.id');
        $final = $this->patchJson($this->lineupPath($team).'/'.$id, ['action' => 'finalize', 'version' => $created->json('data.version'), 'team_version' => $team->version])
            ->assertOk()->assertJsonPath('data.status', 'finalized')->assertJsonPath('data.finalized_by', $user->id);
        $this->getJson($this->lineupPath($team).'/'.$id)->assertOk()->assertJsonPath('data.version', $final->json('data.version'));
        $this->assertDatabaseHas('team_lineups', ['id' => $id, 'status' => 'finalized', 'created_by' => $user->id, 'finalized_by' => $user->id]);
        $this->assertDatabaseCount('team_lineup_entries', 1);
        $this->assertDatabaseCount('organization_audit_events', 2);
    }

    #[Group('tenant-isolation')]
    public function test_foreign_team_lineup_or_membership_ids_return_404_without_writes(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $foreign = TeamLineup::factory()->create();
        $other = Team::factory()->create(['organization_id' => $org->id, 'game_id' => $team->game_id]);
        $otherPeriod = TeamMembership::factory()->create(['team_id' => $other->id]);
        $this->getJson($this->lineupPath($team).'/'.$foreign->id)->assertNotFound();
        $this->getJson($this->lineupPath(Team::findOrFail($foreign->team_id)))->assertNotFound();
        $this->postJson($this->lineupPath($team), [...$this->lineupPayload($team, $period), 'entries' => [$this->lineupChoice($otherPeriod)]])->assertNotFound();
        $this->assertDatabaseCount('team_lineups', 1);
        $this->assertDatabaseCount('team_lineup_entries', 0);
    }

    #[Group('tenant-isolation')]
    public function test_multi_tenant_manager_cannot_use_lineups_under_another_tenant_or_team(): void
    {
        [$org, $user, $team, $period] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $foreign = TeamLineup::factory()->create();
        $foreignTeam = Team::findOrFail($foreign->team_id);
        $this->grantPeopleAccess(Organization::findOrFail($foreign->organization_id), $user, ['sports.read', 'teams.manage']);
        $other = Team::factory()->create(['organization_id' => $org->id, 'game_id' => $team->game_id]);
        $sameTenant = TeamLineup::factory()->create(['team_id' => $other->id]);
        $this->getJson($this->lineupPath($foreignTeam).'/'.$foreign->id)->assertOk()->assertJsonPath('data.id', $foreign->id);
        $this->getJson($this->lineupPath($other).'/'.$sameTenant->id)->assertOk()->assertJsonPath('data.id', $sameTenant->id);

        foreach ([$foreign, $sameTenant] as $lineup) {
            $this->getJson($this->lineupPath($team).'/'.$lineup->id)->assertNotFound();
            $this->patchJson($this->lineupPath($team).'/'.$lineup->id, ['action' => 'cancel', 'version' => $lineup->version])->assertNotFound();
            $this->assertDatabaseHas('team_lineups', ['id' => $lineup->id, 'version' => $lineup->version, 'status' => 'draft']);
        }
        $spoofedPath = '/api/v1/tenants/'.$org->id.'/teams/'.$foreignTeam->id.'/lineups';
        $this->getJson($spoofedPath)->assertNotFound();
        $this->postJson($spoofedPath, $this->lineupPayload($team, $period))->assertNotFound();
        $this->getJson($this->lineupPath($team))->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('team_lineups', 2);
        $this->assertDatabaseCount('team_lineup_entries', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership', 404])]
    #[TestWith(['role', 403])]
    public function test_revocation_blocks_previously_read_lineups_and_mutations(string $revoked, int $status): void
    {
        [$org, $user, $team, $period] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $lineup = app(TeamLineupService::class)->create($this->personContext($org, $user), $team->id, $this->lineupPayload($team, $period));
        $this->getJson($this->lineupPath($team))->assertOk();
        $this->getJson($this->lineupPath($team).'/'.$lineup->id)->assertOk();
        $model = $revoked === 'membership' ? OrganizationMembership::class : OrganizationRoleAssignment::class;
        $model::query()->where('organization_id', $org->id)->where('user_id', $user->id)
            ->firstOrFail()->forceFill(['status' => 'revoked'])->save();

        $this->getJson($this->lineupPath($team))->assertStatus($status)->assertJsonMissingPath('data');
        $this->getJson($this->lineupPath($team).'/'.$lineup->id)->assertStatus($status)->assertJsonMissingPath('data');
        $this->postJson($this->lineupPath($team), $this->lineupPayload($team, $period))->assertStatus($status);
        $this->patchJson($this->lineupPath($team).'/'.$lineup->id, ['action' => 'cancel', 'version' => $lineup->version])->assertStatus($status);
        $this->patchJson($this->lineupPath($team).'/'.$lineup->id, ['action' => 'finalize', 'version' => $lineup->version, 'team_version' => $team->version])->assertStatus($status);

        $this->assertDatabaseHas('team_lineups', ['id' => $lineup->id, 'status' => 'draft', 'version' => $lineup->version, 'finalized_at' => null]);
        $this->assertDatabaseHas('team_lineup_entries', ['lineup_id' => $lineup->id, 'membership_id' => $period->id]);
        $this->assertDatabaseCount('team_lineups', 1);
        $this->assertDatabaseCount('team_lineup_entries', 1);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    #[Group('tenant-isolation')]
    public function test_removing_team_management_preserves_lineup_reads_but_blocks_creation_and_actions(): void
    {
        [$org, $user, $team, $period] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $lineup = app(TeamLineupService::class)->create($this->personContext($org, $user), $team->id, $this->lineupPayload($team, $period));
        $this->getJson($this->lineupPath($team).'/'.$lineup->id)->assertOk();
        OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)
            ->firstOrFail()->role->forceFill(['permissions' => ['sports.read']])->save();

        $this->getJson($this->lineupPath($team))->assertOk()->assertJsonPath('data.0.id', $lineup->id);
        $this->getJson($this->lineupPath($team).'/'.$lineup->id)->assertOk()->assertJsonPath('data.id', $lineup->id);
        $this->postJson($this->lineupPath($team), $this->lineupPayload($team, $period))->assertForbidden();
        $this->patchJson($this->lineupPath($team).'/'.$lineup->id, ['action' => 'cancel', 'version' => $lineup->version])->assertForbidden();
        $this->patchJson($this->lineupPath($team).'/'.$lineup->id, ['action' => 'finalize', 'version' => $lineup->version, 'team_version' => $team->version])->assertForbidden();

        $this->assertDatabaseHas('team_lineups', ['id' => $lineup->id, 'status' => 'draft', 'version' => $lineup->version, 'finalized_at' => null]);
        $this->assertDatabaseHas('team_lineup_entries', ['lineup_id' => $lineup->id, 'membership_id' => $period->id]);
        $this->assertDatabaseCount('team_lineups', 1);
        $this->assertDatabaseCount('team_lineup_entries', 1);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    public function test_stale_team_and_lineup_versions_return_409_and_final_snapshot_is_terminal(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $this->postJson($this->lineupPath($team), [...$this->lineupPayload($team, $period), 'team_version' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'TEAM_LINEUP_TEAM_STALE');
        $draft = app(TeamLineupService::class)->create($this->personContext($org, $user), $team->id, $this->lineupPayload($team, $period));
        $this->patchJson($this->lineupPath($team).'/'.$draft->id, ['action' => 'cancel', 'version' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'TEAM_LINEUP_VERSION_STALE');
        $final = $this->patchJson($this->lineupPath($team).'/'.$draft->id, ['action' => 'finalize', 'version' => $draft->version, 'team_version' => $team->version])->assertOk();
        $this->patchJson($this->lineupPath($team).'/'.$draft->id, ['action' => 'cancel', 'version' => $final->json('data.version')])
            ->assertConflict()->assertJsonPath('code', 'TEAM_LINEUP_IMMUTABLE');
        $this->assertDatabaseCount('organization_audit_events', 2);
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function invalidComposition(): array
    {
        return ['unknown' => [['organization_id' => 'spoof'], 'organization_id'],
            'frozen pseudonym' => [['entries' => [['membership_id' => '11111111-1111-4111-8111-111111111111', 'membership_version' => '11111111-1111-4111-8111-111111111111', 'slot_key' => 'starter', 'pseudonym' => 'spoof']]], 'entries.0'],
            'purpose' => [['purpose' => 'tournament'], 'purpose'], 'label required' => [['label' => ''], 'label'], 'label length' => [['label' => str_repeat('a', 121)], 'label'],
            'team version' => [['team_version' => 'invalid'], 'team_version'], 'timezone required' => [['effective_at' => '2026-10-13T11:00:00'], 'effective_at'],
            'relative date' => [['effective_at' => 'yesterday'], 'effective_at'], 'empty' => [['entries' => []], 'entries'],
            'max entries' => [['entries' => array_fill(0, 51, ['membership_id' => '11111111-1111-4111-8111-111111111111', 'membership_version' => '11111111-1111-4111-8111-111111111111', 'slot_key' => 'starter'])], 'entries'],
            'not list' => [['entries' => ['key' => ['membership_id' => 'bad']]], 'entries'],
            'bad source' => [['entries' => [['membership_id' => 'bad', 'membership_version' => 'bad', 'slot_key' => 'starter']]], 'entries.0.membership_id'],
            'bad slot' => [['entries' => [['membership_id' => '11111111-1111-4111-8111-111111111111', 'membership_version' => '11111111-1111-4111-8111-111111111111', 'slot_key' => 'coach']]], 'entries.0.slot_key']];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidComposition')]
    public function test_invalid_composition_is_422_localized_and_atomic(array $changes, string $field): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $response = $this->postJson($this->lineupPath($team), [...$this->lineupPayload($team, $period), ...$changes])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertNotEmpty($response->json('errors')[$field][0]);
        $this->assertDatabaseCount('team_lineups', 0);
        $this->assertDatabaseCount('team_lineup_entries', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_duplicate_source_no_starter_and_unknown_action_fields_are_rejected(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $choice = $this->lineupChoice($period);
        $this->postJson($this->lineupPath($team), [...$this->lineupPayload($team, $period), 'entries' => [$choice, $choice]])->assertUnprocessable()->assertJsonValidationErrors('entries.0.membership_id');
        $this->postJson($this->lineupPath($team), [...$this->lineupPayload($team, $period), 'entries' => [$this->lineupChoice($period, 'substitute')]])->assertUnprocessable()
            ->assertJsonPath('errors.entries.0', __('team_lineups.starter'));
        $draft = app(TeamLineupService::class)->create($this->personContext($org, $user), $team->id, $this->lineupPayload($team, $period));
        $this->patchJson($this->lineupPath($team).'/'.$draft->id, ['action' => 'cancel', 'version' => $draft->version, 'finalized_by' => $user->id])->assertUnprocessable()->assertJsonValidationErrors('finalized_by');
        $this->patchJson($this->lineupPath($team).'/'.$draft->id, ['action' => ['finalize'], 'version' => $draft->version])->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->assertDatabaseHas('team_lineups', ['id' => $draft->id, 'status' => 'draft', 'version' => $draft->version]);
    }

    public function test_filters_pagination_and_eager_loaded_entries_have_constant_query_count(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $service = app(TeamLineupService::class);
        for ($i = 0; $i < 3; $i++) {
            $service->create($this->personContext($org, $user), $team->id, [...$this->lineupPayload($team, $period), 'purpose' => $i === 0 ? 'registration' : 'match']);
        }
        DB::enableQueryLog();
        $this->getJson($this->lineupPath($team).'?per_page=2&purpose=match')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $queries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'from "team_lineup_entries"'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->getJson($this->lineupPath($team).'?status=finalized')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->lineupPath($team).'?per_page=101')->assertUnprocessable();
    }

    public function test_mfa_and_explicit_tenant_confirmation_guard_mutations(): void
    {
        [$org,$user,$team,$period] = $this->lineupEditor();
        $this->actingAsWithMfa($user);
        $this->withSession([MfaSession::PROOF_KEY => null])->postJson($this->lineupPath($team), $this->lineupPayload($team, $period))->assertForbidden();
        $this->actingAsWithMfa($user);
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $org->id, 'actor_user_id' => $user->id, 'revision' => $revision, 'confirmed' => false]])
            ->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision)->postJson($this->lineupPath($team), $this->lineupPayload($team, $period))
            ->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->assertDatabaseCount('team_lineups', 0);
    }
}
