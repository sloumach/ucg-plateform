<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Teams\Domain\Models\Team;
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
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class TeamControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_guest_cannot_read_or_create_teams(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->path($org))->assertUnauthorized();
        $this->postJson($this->path($org), $this->payload())->assertUnauthorized();
    }

    public function test_creation_returns_private_identity_and_minimal_atomic_audit(): void
    {
        $org = $this->editor();
        $response = $this->postJson($this->path($org), [...$this->payload(), 'public_description' => 'PRIVATE_TEXT'])->assertCreated()
            ->assertJsonPath('data.game_key', 'valorant')->assertJsonPath('data.publication_status', 'private')
            ->assertJsonMissingPath('data.organization_id')->assertJsonPath('notification.message', 'L’équipe a été enregistrée.');
        $this->assertDatabaseHas('teams', ['id' => $response->json('data.id'), 'organization_id' => $org->id, 'season' => '2026']);
        $this->assertDatabaseCount('organization_audit_events', 1);
        $event = DB::table('organization_audit_events')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('PRIVATE_TEXT', json_encode($event, JSON_THROW_ON_ERROR));
        $this->assertSame($response->json('data.id'), json_decode($event->after, true)['team_id']);
        $this->assertSame($response->json('meta.request_id'), $event->request_id);
    }

    public function test_slug_is_reserved_after_archival_and_can_be_used_in_another_tenant(): void
    {
        $org = $this->editor();
        $payload = $this->payload();
        $created = $this->postJson($this->path($org), $payload)->assertCreated();
        $this->patchJson($this->path($org, $created->json('data.id')), ['version' => $created->json('data.version'), 'status' => 'archived'])->assertOk();
        $this->postJson($this->path($org), $payload)->assertConflict()->assertJsonPath('code', 'TEAM_SLUG_ALREADY_EXISTS');
        Team::factory()->create(['slug' => $payload['slug']]);
        $this->assertDatabaseCount('teams', 2);
        $this->assertDatabaseCount('organization_audit_events', 2);
    }

    #[Group('tenant-isolation')]
    public function test_foreign_known_ids_do_not_leak_for_a_member_of_both_organizations(): void
    {
        $org = $this->editor();
        $foreign = Team::factory()->create();
        $this->grantPeopleAccess(Organization::findOrFail($foreign->organization_id), User::findOrFail(auth()->id()), ['sports.read', 'teams.manage']);
        $denied = $this->getJson($this->path($org, $foreign->id))->assertNotFound();
        $missing = $this->getJson($this->path($org, (string) Str::uuid()))->assertNotFound();
        $this->assertSame($missing->json('code'), $denied->json('code'));
        $this->assertSame($missing->json('message'), $denied->json('message'));
        $this->assertStringNotContainsString($foreign->id, (string) $denied->getContent());
        $this->assertStringNotContainsString($foreign->organization_id, (string) $denied->getContent());
        $this->patchJson($this->path($org, $foreign->id), ['version' => $foreign->version, 'name' => 'Hijack'])->assertNotFound();
        $this->patchJson($this->path($org, $foreign->id), ['version' => $foreign->version, 'status' => 'archived'])->assertNotFound();
        $this->assertDatabaseHas('teams', ['id' => $foreign->id, 'name' => 'Équipe pilote', 'status' => 'active', 'version' => $foreign->version]);
        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership', 404])]
    #[TestWith(['role', 403])]
    public function test_revocation_after_team_reads_blocks_alpha_writes_without_revoking_beta(string $revocation, int $status): void
    {
        $org = $this->editor();
        $user = User::findOrFail(auth()->id());
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, ['sports.read', 'teams.manage']);
        $team = Team::factory()->create(['organization_id' => $org->id, 'name' => 'Alpha private']);
        $beta = Team::factory()->create(['organization_id' => $other->id, 'name' => 'Beta private']);
        $this->getJson($this->path($org))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->path($org, $team->id))->assertOk()->assertJsonPath('data.name', 'Alpha private');
        if ($revocation === 'membership') {
            OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $user->id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        } else {
            OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        }

        $this->getJson($this->path($org))->assertStatus($status)->assertJsonMissingPath('data');
        $this->getJson($this->path($org, $team->id))->assertStatus($status)->assertJsonMissingPath('data');
        $this->postJson($this->path($org), $this->payload())->assertStatus($status);
        $this->patchJson($this->path($org, $team->id), ['version' => $team->version, 'name' => 'Hijacked'])->assertStatus($status);
        $this->patchJson($this->path($org, $team->id), ['version' => $team->version, 'status' => 'archived'])->assertStatus($status);

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'version' => $team->version, 'name' => 'Alpha private', 'status' => 'active']);
        $this->assertDatabaseCount('teams', 2);
        $this->assertDatabaseCount('organization_audit_events', 0);
        $this->getJson($this->path($other, $beta->id))->assertOk()->assertJsonPath('data.name', 'Beta private');
        $this->patchJson($this->path($other, $beta->id), ['version' => $beta->version, 'name' => 'Beta authorized'])->assertOk();
        $this->assertDatabaseHas('organization_audit_events', ['organization_id' => $other->id]);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    #[Group('tenant-isolation')]
    public function test_removing_team_management_keeps_reads_but_blocks_creation_editing_and_archival(): void
    {
        $org = $this->editor();
        $user = User::findOrFail(auth()->id());
        $team = Team::factory()->create(['organization_id' => $org->id, 'name' => 'Preserved']);
        $this->getJson($this->path($org, $team->id))->assertOk();
        OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)->firstOrFail()
            ->role->forceFill(['permissions' => ['sports.read']])->save();

        $this->getJson($this->path($org))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->path($org, $team->id))->assertOk()->assertJsonPath('data.name', 'Preserved');
        $this->postJson($this->path($org), $this->payload())->assertForbidden();
        $this->patchJson($this->path($org, $team->id), ['version' => $team->version, 'name' => 'Hijacked'])->assertForbidden();
        $this->patchJson($this->path($org, $team->id), ['version' => $team->version, 'status' => 'archived'])->assertForbidden();
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'version' => $team->version, 'name' => 'Preserved', 'status' => 'active']);
        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_listing_is_bounded_filtered_sorted_and_empty_results_are_successful(): void
    {
        $org = $this->editor(['sports.read']);
        Team::factory()->create(['organization_id' => $org->id, 'name' => 'Alpha%', 'kind' => 'academy', 'season' => '2026']);
        Team::factory()->create(['organization_id' => $org->id, 'name' => 'AlphaBeta', 'kind' => 'reserve', 'season' => '2025']);
        Team::factory()->create(['name' => 'PRIVATE_FOREIGN']);
        $this->getJson($this->path($org).'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson($this->path($org).'?q=Alpha%25')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alpha%');
        $this->getJson($this->path($org).'?kind=academy&season=2026&status=active')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->path($org).'?q=absent')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($org).'?sort=name%3Bdelete')->assertUnprocessable();
        $this->getJson($this->path($org).'?per_page=101')->assertUnprocessable();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): array
    {
        return ['unknown tenant' => [['organization_id' => 'spoof'], 'organization_id'],
            'unknown publication' => [['published' => true], 'published'],
            'slug format' => [['slug' => 'Bad Slug'], 'slug'],
            'season empty' => [['season' => ' '], 'season'],
            'name length' => [['name' => str_repeat('x', 121)], 'name'],
            'unknown category' => [['kind' => 'owner'], 'kind'],
            'category missing' => [['kind' => 'custom'], 'custom_category'],
            'category unexpected' => [['custom_category' => 'Senior'], 'custom_category'],
            'color injection' => [['color' => 'url(javascript:x)'], 'color'],
            'short name length' => [['short_name' => str_repeat('x', 17)], 'short_name'],
            'description length' => [['public_description' => str_repeat('x', 1001)], 'public_description'],
            'game uuid' => [['game_id' => 'not-uuid'], 'game_id'],
            'invalid state' => [['status' => 'published'], 'status'],
            'spoof version' => [['version' => (string) Str::uuid()], 'version']];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('invalidInputs')]
    public function test_invalid_payload_is_localized_without_partial_writes(array $change, string $field): void
    {
        $org = $this->editor();
        $this->postJson($this->path($org), [...$this->payload(), ...$change])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_custom_category_and_partial_identity_edits_preserve_omitted_values(): void
    {
        $org = $this->editor();
        $created = $this->postJson($this->path($org), [...$this->payload(), 'kind' => 'custom', 'custom_category' => 'Mixte', 'color' => '#12Ab34'])->assertCreated();
        $this->patchJson($this->path($org, $created->json('data.id')), ['version' => $created->json('data.version'), 'name' => 'Nouveau nom'])->assertOk()
            ->assertJsonPath('data.custom_category', 'Mixte')->assertJsonPath('data.color', '#12Ab34');
    }

    public function test_retired_global_and_local_games_block_new_use_but_history_can_be_archived(): void
    {
        $org = $this->editor();
        $game = $this->game();
        $team = Team::factory()->create(['organization_id' => $org->id]);
        OrganizationGame::factory()->create(['organization_id' => $org->id, 'game_id' => $game->id, 'enabled' => false]);
        $this->postJson($this->path($org), $this->payload())->assertConflict()->assertJsonPath('code', 'GAME_CONTEXT_UNAVAILABLE');
        $this->patchJson($this->path($org, $team->id), ['version' => $team->version, 'name' => 'Forbidden'])->assertConflict();
        $game->forceFill(['active' => false])->save();
        $this->getJson($this->path($org, $team->id))->assertOk();
        $this->patchJson($this->path($org, $team->id), ['version' => $team->version, 'status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertDatabaseCount('teams', 1);
    }

    public function test_stale_patch_and_archive_reactivation_never_overwrite_history(): void
    {
        $org = $this->editor();
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $this->patchJson($this->path($org, $team->id), ['version' => (string) Str::uuid(), 'name' => 'Stale'])->assertConflict()->assertJsonPath('code', 'TEAM_VERSION_STALE');
        $archived = $this->patchJson($this->path($org, $team->id), ['version' => $team->version, 'status' => 'archived'])->assertOk();
        $this->patchJson($this->path($org, $team->id), ['version' => $archived->json('data.version'), 'status' => 'active'])->assertConflict()->assertJsonPath('code', 'TEAM_ARCHIVED');
        $this->deleteJson($this->path($org, $team->id))->assertStatus(405);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    /** @return array<string, array{string}> */
    public static function immutableFields(): array
    {
        return ['game' => ['game_id'], 'slug' => ['slug'], 'season' => ['season'], 'tenant' => ['organization_id']];
    }

    #[DataProvider('immutableFields')]
    public function test_patch_cannot_move_or_repurpose_a_team(string $field): void
    {
        $org = $this->editor();
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $this->patchJson($this->path($org, $team->id), ['version' => $team->version, $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_mfa_proof_and_tenant_confirmation_are_required(): void
    {
        $org = $this->editor();
        $user = User::findOrFail(auth()->id());
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->postJson($this->path($org), $this->payload())->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');
        $this->actingAsWithMfa($user);
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $org->id, 'actor_user_id' => $user->id, 'revision' => $revision, 'confirmed' => false]])
            ->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision);
        $this->postJson($this->path($org), $this->payload())->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->assertDatabaseCount('teams', 0);
    }

    /** @param list<string> $permissions */
    private function editor(array $permissions = ['sports.read', 'teams.manage']): Organization
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return $org;
    }

    private function game(): GameCatalogueEntry
    {
        return GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'valorant')->firstOrFail();
    }

    private function path(Organization $org, ?string $id = null): string
    {
        return '/api/v1/tenants/'.$org->id.'/teams'.($id === null ? '' : '/'.$id);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['game_id' => $this->game()->id, 'slug' => 'valorant-premiere-2026', 'season' => '2026', 'name' => 'Première équipe', 'kind' => 'first'];
    }
}
