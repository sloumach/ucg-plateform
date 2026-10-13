<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Contracts\PlatformCatalogueGuard;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Application\Services\PlatformRoleService;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\PlatformRoleType;
use App\Modules\People\Application\Contracts\GameProfileCatalogue;
use App\Modules\Teams\Application\Services\EffectiveGameCatalogue;
use App\Modules\Teams\Application\Services\GameCatalogueService;
use App\Modules\Teams\Application\Services\OrganizationGameService;
use App\Modules\Teams\Domain\Contracts\GameCatalogueRepository;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class GameCataloguePolicyTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_member_and_support_cannot_modify_global_metadata(): void
    {
        $member = User::factory()->withMfa()->create();
        $this->actingAsWithMfa($member);
        $this->postJson('/api/v1/game-catalogue', $this->payload())->assertForbidden();
        PlatformRole::factory()->create(['user_id' => $member->id, 'role' => PlatformRoleType::Support]);
        $this->actingAsWithMfa($member);
        $this->getJson('/api/v1/game-catalogue')->assertOk()->assertJsonPath('meta.can_manage', false);
        $this->postJson('/api/v1/game-catalogue', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('game_catalogue_events', 0);
    }

    public function test_mfa_proof_is_required_even_for_superadmin(): void
    {
        $user = $this->operator();
        $this->actingAsWithMfa($user)->withSession([MfaSession::PROOF_KEY => null]);
        $this->postJson('/api/v1/game-catalogue', $this->payload())->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');
        $this->assertDatabaseCount('game_catalogue_events', 0);
    }

    public function test_unverified_or_unenrolled_superadmin_fails_the_public_guard(): void
    {
        $user = $this->operator();
        $guard = app(PlatformCatalogueGuard::class);
        $this->assertTrue($guard->allows($user->id));
        $user->forceFill(['email_verified_at' => null])->save();
        $this->assertFalse($guard->allows($user->id));
        $user->forceFill(['email_verified_at' => now(), 'mfa_confirmed_at' => null])->save();
        $this->assertFalse($guard->allows($user->id));
    }

    public function test_direct_service_call_cannot_bypass_global_authorization(): void
    {
        $user = User::factory()->withMfa()->create();
        try {
            app(GameCatalogueService::class)->save($user->id, (string) Str::uuid(), $this->payload());
            $this->fail('Expected authorization denial.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('game_catalogue_entries', 18);
            $this->assertDatabaseCount('game_catalogue_events', 0);
        }
    }

    public function test_global_audit_failure_rolls_back_entry_and_catalogue_revision(): void
    {
        $user = $this->operator();
        $real = app(GameCatalogueRepository::class);
        $repository = Mockery::mock(GameCatalogueRepository::class);
        foreach (['revision', 'snapshot', 'count', 'save', 'advanceRevision'] as $method) {
            $repository->shouldReceive($method)->andReturnUsing($real->$method(...));
        }
        $repository->shouldReceive('audit')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(GameCatalogueRepository::class, $repository);
        $revision = $this->revision();
        try {
            app(GameCatalogueService::class)->save($user->id, (string) Str::uuid(), $this->payload());
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
            $this->assertSame($revision, $this->revision());
            $this->assertDatabaseCount('game_catalogue_entries', 18);
            $this->assertDatabaseCount('game_catalogue_events', 0);
        }
    }

    public function test_tenant_audit_failure_rolls_back_only_that_tenant_setting(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'games.manage']);
        $journal = Mockery::mock(TenantAuditJournal::class);
        $journal->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(TenantAuditJournal::class, $journal);
        $game = GameCatalogueEntry::query()->where('kind', 'game')->firstOrFail();
        try {
            app(OrganizationGameService::class)->save($this->personContext($org, $user), $game->id,
                ['enabled' => false, 'restrictions' => [], 'version' => null, 'catalogue_version' => $this->revision()]);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
            $this->assertDatabaseCount('organization_games', 0);
            $this->assertDatabaseCount('game_catalogue_events', 0);
        }
    }

    public function test_profile_catalogue_query_count_does_not_grow_with_the_number_of_games(): void
    {
        $org = Organization::factory()->create();
        $catalogue = app(GameProfileCatalogue::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertCount(4, $catalogue->entries($org->id));
        $before = count(DB::getQueryLog());
        DB::disableQueryLog();
        GameCatalogueEntry::factory()->count(12)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertCount(4, $catalogue->entries($org->id));
        $this->assertSame($before, count(DB::getQueryLog()));
        $this->assertLessThanOrEqual(3, $before);
        DB::disableQueryLog();
    }

    public function test_game_quota_rejects_new_rows_without_discarding_existing_references(): void
    {
        $user = $this->operator();
        GameCatalogueEntry::factory()->count(96)->create();
        $this->actingAsWithMfa($user);
        $this->postJson('/api/v1/game-catalogue', $this->payload())->assertConflict()->assertJsonPath('code', 'GAME_CATALOGUE_LIMIT_REACHED');
        $this->assertDatabaseCount('game_catalogue_events', 0);
        $this->assertSame(100, GameCatalogueEntry::query()->where('kind', 'game')->count());
    }

    public function test_shared_metadata_cache_never_caches_tenant_activations_and_follows_global_revision(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $database = Mockery::mock(ConnectionInterface::class);
        $this->assertInstanceOf(ConnectionInterface::class, $database);
        $database->shouldReceive('transactionLevel')->andReturn(0);
        $catalogue = new EffectiveGameCatalogue(app(GameCatalogueRepository::class), $database, new CacheRepository(new ArrayStore));
        $this->assertCount(4, $catalogue->profiles($org->id));
        $game = GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'valorant')->firstOrFail();
        OrganizationGame::factory()->create(['organization_id' => $org->id, 'game_id' => $game->id, 'enabled' => false]);
        $this->assertCount(3, $catalogue->profiles($org->id));
        $this->assertCount(4, $catalogue->profiles($other->id));
        $operator = $this->operator();
        app(GameCatalogueService::class)->save($operator->id, (string) Str::uuid(), ['name' => $game->name, 'active' => false,
            'definition' => $game->definition, 'version' => $game->version, 'catalogue_version' => $this->revision(), 'confirm_global' => true], $game->id);
        $this->assertCount(3, $catalogue->profiles($other->id));
    }

    public function test_captured_tenant_context_cannot_bypass_membership_revocation(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'games.manage']);
        $context = $this->personContext($org, $user);
        OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $user->id)->update(['status' => 'revoked']);
        try {
            app(OrganizationGameService::class)->save($context, GameCatalogueEntry::query()->where('kind', 'game')->firstOrFail()->id,
                ['enabled' => true, 'restrictions' => [], 'version' => null, 'catalogue_version' => $this->revision()]);
            $this->fail('Captured context cannot authorize a revoked member.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('organization_games', 0);
            $this->assertDatabaseCount('organization_audit_events', 0);
        }
    }

    private function operator(): User
    {
        $user = User::factory()->withMfa()->create();
        app(PlatformRoleService::class)->configure($user->id, PlatformRoleType::Superadmin, true, null, 'Initialisation de test', (string) Str::uuid(), true);

        return $user;
    }

    private function revision(): string
    {
        return DB::table('game_catalogue_state')->value('version');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['kind' => 'game', 'key' => 'new-game', 'name' => 'Nouveau jeu', 'active' => true,
            'definition' => ['platforms' => ['pc' => ['steam']], 'regions' => ['eu']],
            'catalogue_version' => $this->revision(), 'confirm_global' => true];
    }
}
