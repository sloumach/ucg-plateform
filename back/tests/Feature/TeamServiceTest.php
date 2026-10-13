<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Teams\Application\Services\TeamService;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class TeamServiceTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string, array{list<string>, bool, bool}> */
    public static function permissions(): array
    {
        return ['none' => [[], false, false], 'reader' => [['sports.read'], true, false],
            'sports writer' => [['sports.read', 'sports.write'], true, false],
            'catalogue manager' => [['sports.read', 'games.manage'], true, false],
            'manager without read' => [['teams.manage'], false, false],
            'manager' => [['sports.read', 'teams.manage'], true, true]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_policies_require_explicit_team_management_and_matching_actor(array $permissions, bool $read, bool $write): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($read, Gate::forUser($user)->allows('viewAny', [Team::class, $context]));
        $this->assertSame($write, Gate::forUser($user)->allows('manage', [Team::class, $context]));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('viewAny', [Team::class, $context]));
    }

    public function test_owner_and_technical_operator_do_not_get_business_rights_implicitly(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $owner = User::findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('manage', [Team::class, $this->personContext($org, $owner)]));
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'teams.manage']);
        PlatformRole::factory()->create(['user_id' => $user->id]);
        $this->assertFalse(Gate::forUser($user)->allows('manage', [Team::class, $this->personContext($org, $user)]));
    }

    public function test_audit_failure_rolls_back_team_creation(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'teams.manage']);
        $audit = Mockery::mock(TenantAuditJournal::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(TenantAuditJournal::class, $audit);
        try {
            app(TeamService::class)->save($this->personContext($org, $user), $this->payload());
            $this->fail('Audit failure must roll back creation.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
            $this->assertDatabaseCount('teams', 0);
        }
    }

    public function test_captured_context_cannot_bypass_membership_revocation(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'teams.manage']);
        $context = $this->personContext($org, $user);
        OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->expectException(AuthorizationException::class);
        app(TeamService::class)->save($context, $this->payload());
    }

    public function test_direct_service_validates_spoofed_tenant_and_empty_patch(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'teams.manage']);
        try {
            app(TeamService::class)->save($this->personContext($org, $user), [...$this->payload(), 'organization_id' => $org->id]);
            $this->fail('Spoofed tenant must be rejected.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('organization_id', $error->errors());
        }
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $this->expectException(ValidationException::class);
        app(TeamService::class)->save($this->personContext($org, $user), ['version' => $team->version], $team->id);
    }

    public function test_list_query_count_does_not_grow_with_page_size(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read']);
        Team::factory()->count(5)->create(['organization_id' => $org->id]);
        $context = $this->personContext($org, $user);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $small = app(TeamService::class)->paginate($context, ['per_page' => 1]);
        foreach ($small->items() as $team) {
            $this->assertSame('VALORANT', $team->game->name);
        }
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();
        $large = app(TeamService::class)->paginate($context, ['per_page' => 5]);
        foreach ($large->items() as $team) {
            $this->assertSame('VALORANT', $team->game->name);
        }
        $this->assertSame($count, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['game_id' => GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'valorant')->firstOrFail()->id,
            'slug' => 'pilote-2026', 'season' => '2026', 'name' => 'Équipe pilote', 'kind' => 'first'];
    }
}
