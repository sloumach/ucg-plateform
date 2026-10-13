<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Contracts\GameProfileCatalogue;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class OrganizationGameControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_tenant_catalogue_is_bounded_and_empty_settings_preserve_legacy_games(): void
    {
        $org = $this->editor(['sports.read']);
        $this->getJson($this->path($org).'?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/tenants/'.$org->id.'/game-profile-catalogue')->assertOk()->assertJsonCount(4, 'data.games');
        $this->assertDatabaseCount('organization_games', 0);
    }

    public function test_disabling_one_organization_does_not_change_global_or_other_tenant_catalogue(): void
    {
        $org = $this->editor();
        $user = User::findOrFail(auth()->id());
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, ['sports.read']);
        $game = $this->game();
        $revision = $this->revision();
        $this->patchJson($this->path($org, $game), $this->payload(false))->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson($this->path($org, $game))->assertOk()->assertJsonCount(0, 'data.effective_options.platform')->assertJsonCount(3, 'data.options.platform');
        $this->getJson('/api/v1/tenants/'.$org->id.'/game-profile-catalogue')->assertOk()->assertJsonCount(3, 'data.games');
        // Public service lookup is deliberately tenant-keyed, independently of the selected HTTP context.
        $catalogue = app(GameProfileCatalogue::class);
        $this->assertCount(4, $catalogue->entries($other->id));
        $this->assertSame($revision, $this->revision());
        $this->assertTrue($game->refresh()->active);
        $this->assertDatabaseCount('organization_games', 1);
        $this->assertDatabaseCount('game_catalogue_events', 0);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    public function test_platform_region_and_provider_restrictions_affect_profiles_and_accounts(): void
    {
        $org = $this->editor(['sports.read', 'sports.write', 'games.manage']);
        $game = $this->game();
        $this->patchJson($this->path($org, $game), [...$this->payload(), 'restrictions' => ['platform' => ['pc'], 'region' => ['eu'], 'provider' => []]])->assertOk();
        $person = Person::factory()->create(['organization_id' => $org->id]);
        $profiles = '/api/v1/tenants/'.$org->id.'/people/'.$person->id.'/game-profiles';
        $base = ['game_key' => 'valorant', 'platform_key' => 'pc', 'region_key' => 'eu'];
        $this->postJson($profiles, [...$base, 'platform_key' => 'xbox'])->assertConflict();
        $this->postJson($profiles, [...$base, 'region_key' => 'na'])->assertConflict();
        $created = $this->postJson($profiles, $base)->assertCreated();
        $this->postJson($profiles.'/'.$created->json('data.id').'/external-accounts', ['provider_key' => 'riot', 'external_id' => 'id-test'])->assertConflict();
        $this->assertDatabaseCount('person_external_accounts', 0);
    }

    public function test_new_games_require_explicit_org_activation(): void
    {
        $org = $this->editor();
        $game = GameCatalogueEntry::factory()->create(['key' => 'new-game']);
        $catalogue = app(GameProfileCatalogue::class);
        $this->assertNotContains('new-game', array_column($catalogue->entries($org->id), 'key'));
        $this->patchJson($this->path($org, $game), $this->payload())->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertContains('new-game', array_column($catalogue->entries($org->id), 'key'));
    }

    public function test_history_remains_readable_and_archivable_after_org_deactivation(): void
    {
        $org = $this->editor(['sports.read', 'sports.write', 'games.manage']);
        $person = Person::factory()->create(['organization_id' => $org->id]);
        $profile = GameProfile::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        $this->patchJson($this->path($org, $this->game()), $this->payload(false))->assertOk();
        $path = '/api/v1/tenants/'.$org->id.'/people/'.$person->id.'/game-profiles/'.$profile->id;
        $this->getJson($path)->assertOk();
        $this->patchJson($path, ['rank' => 'x', 'version' => $profile->version])->assertConflict();
        $this->patchJson($path, ['status' => 'archived', 'version' => $profile->version])->assertOk();
        $this->assertDatabaseCount('person_game_profiles', 1);
    }

    /** @return array<string, array{list<string>}> */
    public static function insufficientPermissions(): array
    {
        return ['reader' => [['sports.read']], 'sports writer' => [['sports.read', 'sports.write']],
            'manage without read' => [['games.manage']], 'empty' => [[]]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('insufficientPermissions')]
    public function test_org_writes_require_read_and_explicit_game_management(array $permissions): void
    {
        $org = $this->editor($permissions);
        $this->patchJson($this->path($org, $this->game()), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('organization_games', 0);
    }

    public function test_owner_and_technical_operator_do_not_get_implicit_catalogue_business_rights(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $this->actingAsWithMfa(User::findOrFail($org->owner_user_id));
        $this->getJson($this->path($org))->assertForbidden();
        $this->patchJson($this->path($org, $this->game()), $this->payload())->assertForbidden();
        $org = $this->editor();
        PlatformRole::factory()->create(['user_id' => auth()->id()]);
        $this->patchJson($this->path($org, $this->game()), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('organization_games', 0);
    }

    public function test_wrong_tenant_and_spoofed_org_fields_are_rejected(): void
    {
        $org = $this->editor();
        $foreign = Organization::factory()->create();
        $game = $this->game();
        $this->patchJson($this->path($foreign, $game), $this->payload())->assertNotFound();
        $this->patchJson($this->path($org, $game), [...$this->payload(), 'organization_id' => $foreign->id])->assertUnprocessable();
        $this->assertDatabaseCount('organization_games', 0);
    }

    public function test_stale_creation_edit_and_global_versions_cannot_overwrite_settings(): void
    {
        $org = $this->editor();
        $game = $this->game();
        $initial = $this->payload();
        $created = $this->patchJson($this->path($org, $game), $initial)->assertOk();
        $this->patchJson($this->path($org, $game), $initial)->assertConflict()->assertJsonPath('code', 'ORGANIZATION_GAME_VERSION_STALE');
        $version = $created->json('data.version');
        $this->patchJson($this->path($org, $game), [...$initial, 'version' => $version, 'catalogue_version' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    public function test_unavailable_game_unknown_choices_and_global_fields_are_rejected(): void
    {
        $org = $this->editor();
        $game = $this->game();
        $this->patchJson($this->path($org, $game), [...$this->payload(), 'restrictions' => ['platform' => ['switch']]])->assertUnprocessable();
        $this->patchJson($this->path($org, $game), [...$this->payload(), 'name' => 'GLOBAL OVERWRITE'])->assertUnprocessable();
        $game->forceFill(['active' => false])->save();
        $this->patchJson($this->path($org, $game), $this->payload())->assertConflict();
        $this->assertDatabaseCount('organization_games', 0);
    }

    public function test_scoped_restrictions_are_checked_against_the_same_game(): void
    {
        $org = $this->editor();
        $game = $this->game();
        $other = GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'counter-strike-2')->firstOrFail();
        GameCatalogueEntry::factory()->create(['kind' => 'map', 'key' => 'map-test', 'game_id' => $other->id, 'scope_key' => $other->id, 'definition' => null]);
        $this->patchJson($this->path($org, $game), [...$this->payload(), 'restrictions' => ['map' => ['map-test']]])->assertUnprocessable();
        GameCatalogueEntry::factory()->create(['kind' => 'map', 'key' => 'map-test', 'game_id' => $game->id, 'scope_key' => $game->id, 'definition' => null]);
        $this->patchJson($this->path($org, $game), [...$this->payload(), 'restrictions' => ['map' => ['map-test']]])->assertOk();
        $this->getJson($this->path($org, $game))->assertOk()->assertJsonPath('data.effective_options.map.0.key', 'map-test');
    }

    public function test_retired_restrictions_can_be_retained_or_cleared_without_reactivation(): void
    {
        $org = $this->editor();
        $game = $this->game();
        $setting = OrganizationGame::factory()->create(['organization_id' => $org->id, 'game_id' => $game->id, 'restrictions' => ['platform' => ['pc']]]);
        GameCatalogueEntry::query()->where('kind', 'platform')->where('key', 'pc')->update(['active' => false]);
        $this->patchJson($this->path($org, $game), [...$this->payload(false), 'version' => $setting->version, 'restrictions' => ['platform' => ['pc']]])->assertOk();
        $this->assertDatabaseCount('organization_games', 1);
    }

    public function test_org_write_requires_current_mfa_proof_and_tenant_confirmation(): void
    {
        $org = $this->editor();
        $game = $this->game();
        $user = User::findOrFail(auth()->id());
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->patchJson($this->path($org, $game), $this->payload())->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');
        $this->actingAsWithMfa($user);
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $org->id, 'actor_user_id' => $user->id,
            'revision' => $revision, 'confirmed' => false]])->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision);
        $this->patchJson($this->path($org, $game), $this->payload())->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->assertDatabaseCount('organization_games', 0);
    }

    public function test_game_scoped_management_grant_does_not_authorize_organization_settings(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $reader = $this->grantPeopleAccess($org, $user, ['sports.read']);
        $game = $this->game();
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id, 'permissions' => ['games.manage'], 'privileged' => true]);
        OrganizationRoleAssignment::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id,
            'role_id' => $role->id, 'membership_id' => $reader->membership_id, 'scope_type' => 'game', 'scope_id' => $game->id]);
        $this->actingAsWithMfa($user);
        $this->patchJson($this->path($org, $game), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('organization_games', 0);
    }

    /** @param list<string> $permissions */
    private function editor(array $permissions = ['sports.read', 'games.manage']): Organization
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

    private function revision(): string
    {
        return DB::table('game_catalogue_state')->value('version');
    }

    private function path(Organization $org, ?GameCatalogueEntry $game = null): string
    {
        return '/api/v1/tenants/'.$org->id.'/games'.($game === null ? '' : '/'.$game->id);
    }

    /** @return array<string, mixed> */
    private function payload(bool $enabled = true): array
    {
        return ['enabled' => $enabled, 'restrictions' => [], 'version' => null, 'catalogue_version' => $this->revision()];
    }
}
