<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Teams\Application\Contracts\ExternalOpponents;
use App\Modules\Teams\Application\Services\ExternalOpponentService;
use App\Modules\Teams\Application\Services\ExternalOpponentSnapshotService;
use App\Modules\Teams\Domain\Models\ExternalOpponent;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ExternalOpponentServiceTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string, array{list<string>, bool, bool}> */
    public static function permissions(): array
    {
        return ['none' => [[], false, false], 'reader' => [['sports.read'], true, false],
            'writer' => [['sports.read', 'sports.write'], true, false], 'catalogue manager' => [['sports.read', 'games.manage'], true, false],
            'manager without read' => [['teams.manage'], false, false], 'manager' => [['sports.read', 'teams.manage'], true, true]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_policy_requires_explicit_organization_permissions_and_matching_actor(array $permissions, bool $read, bool $write): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($read, Gate::forUser($user)->allows('viewAny', [ExternalOpponent::class, $context]));
        $this->assertSame($write, Gate::forUser($user)->allows('manage', [ExternalOpponent::class, $context]));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('viewAny', [ExternalOpponent::class, $context]));
    }

    public function test_owner_and_technical_operator_have_no_implicit_business_rights(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $owner = User::findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('manage', [ExternalOpponent::class, $this->personContext($org, $owner)]));
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'teams.manage']);
        PlatformRole::factory()->create(['user_id' => $user->id]);
        $this->assertFalse(Gate::forUser($user)->allows('manage', [ExternalOpponent::class, $this->personContext($org, $user)]));
    }

    public function test_audit_failure_rolls_back_creation_update_and_capture(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'teams.manage']);
        $context = $this->personContext($org, $user);
        $opponent = ExternalOpponent::factory()->create(['organization_id' => $org->id]);
        $audit = $this->mock(TenantAuditJournal::class);
        $audit->shouldReceive('record')->times(3)->andThrow(new RuntimeException('Audit unavailable'));
        $writes = [
            fn () => app(ExternalOpponentService::class)->save($context, ['key' => 'new', 'kind' => 'organization', 'name' => 'PUBLIC',
                'source_label' => 'Source', 'observed_at' => '2026-01-01T00:00:00+00:00', 'confirm_public_data' => true]),
            fn () => app(ExternalOpponentService::class)->save($context, ['version' => $opponent->version, 'name' => 'Changed', 'confirm_public_data' => true], $opponent->id),
            fn () => app(ExternalOpponentSnapshotService::class)->capture($context, $opponent->id, $opponent->version, 'Match', true),
        ];
        foreach ($writes as $write) {
            try {
                $write();
                $this->fail('Audit failure must roll back the operation.');
            } catch (RuntimeException $error) {
                $this->assertSame('Audit unavailable', $error->getMessage());
                $this->assertDatabaseCount('external_opponents', 1);
                $this->assertDatabaseCount('external_opponent_snapshots', 0);
                $this->assertSame('Adversaire public', ExternalOpponent::findOrFail($opponent->id)->name);
            }
        }
    }

    public function test_revoked_membership_invalidates_captured_context(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'teams.manage']);
        $context = $this->personContext($org, $user);
        $opponent = ExternalOpponent::factory()->create(['organization_id' => $org->id]);
        OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->expectException(AuthorizationException::class);
        app(ExternalOpponents::class)->capture($context, $opponent->id, $opponent->version, 'Match', true);
    }

    public function test_public_port_returns_frozen_projection_and_rejects_foreign_snapshot_ids(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'teams.manage']);
        $context = $this->personContext($org, $user);
        $opponent = ExternalOpponent::factory()->create(['organization_id' => $org->id]);
        $port = app(ExternalOpponents::class);
        $snapshot = $port->capture($context, $opponent->id, $opponent->version, 'Compétition', true);
        $opponent->forceFill(['name' => 'Renamed'])->save();
        $this->assertSame('Adversaire public', $port->snapshot($context, $snapshot['id'])['name']);
        $this->assertArrayNotHasKey('organization_id', $snapshot);
        $this->assertArrayNotHasKey('user_id', $snapshot);
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, ['sports.read']);
        $this->expectException(ModelNotFoundException::class);
        $port->snapshot($this->personContext($other, $user), $snapshot['id']);
    }

    public function test_direct_service_rejects_unknown_input_and_empty_patch(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'teams.manage']);
        $context = $this->personContext($org, $user);
        try {
            app(ExternalOpponentService::class)->save($context, ['organization_id' => $org->id]);
            $this->fail('Unknown inputs must fail early.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('organization_id', $error->errors());
        }
        $opponent = ExternalOpponent::factory()->create(['organization_id' => $org->id]);
        $this->expectException(ValidationException::class);
        app(ExternalOpponentService::class)->save($context, ['version' => $opponent->version, 'confirm_public_data' => true], $opponent->id);
    }

    public function test_query_count_stays_constant_as_page_size_grows(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read']);
        $game = GameCatalogueEntry::query()->where('kind', 'game')->firstOrFail();
        ExternalOpponent::factory()->count(5)->create(['organization_id' => $org->id, 'game_id' => $game->id]);
        $context = $this->personContext($org, $user);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $small = app(ExternalOpponentService::class)->paginate($context, ['per_page' => 1]);
        foreach ($small->items() as $opponent) {
            $this->assertSame($game->name, $opponent->game?->name);
        }
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();
        $large = app(ExternalOpponentService::class)->paginate($context, ['per_page' => 5]);
        foreach ($large->items() as $opponent) {
            $this->assertSame($game->name, $opponent->game?->name);
        }
        $this->assertSame($count, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }
}
