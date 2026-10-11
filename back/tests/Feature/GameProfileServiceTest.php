<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Data\ExternalGameAccountData;
use App\Modules\People\Application\Data\GameProfileData;
use App\Modules\People\Application\Services\ExternalGameAccountService;
use App\Modules\People\Application\Services\GameProfileProjectionService;
use App\Modules\People\Application\Services\GameProfileService;
use App\Modules\People\Application\Validation\GameProfileInput;
use App\Modules\People\Domain\Models\ExternalGameAccount;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class GameProfileServiceTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string, array{list<string>, bool, bool}> */
    public static function policyPermissions(): array
    {
        return ['ordinary' => [[], false, false], 'reader' => [['sports.read'], true, false],
            'writer' => [['sports.write'], false, true], 'editor' => [['sports.read', 'sports.write'], true, true],
            'administrative reader' => [['administrative.read'], false, false],
            'administrative writer' => [['administrative.write'], false, false]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('policyPermissions')]
    public function test_profile_and_account_policies_require_sports_permissions_and_matching_actor(array $permissions, bool $read, bool $write): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, $permissions);
        $context = $this->personContext($organization, $user);
        $other = User::factory()->withMfa()->create();

        foreach ([GameProfile::class, ExternalGameAccount::class] as $model) {
            $this->assertSame($read, Gate::forUser($user)->allows('viewAny', [$model, $context]));
            $this->assertSame($write, Gate::forUser($user)->allows('manage', [$model, $context]));
            $this->assertFalse(Gate::forUser($other)->allows('viewAny', [$model, $context]));
        }
    }

    public function test_direct_service_validates_input_and_never_accepts_a_spoofed_tenant(): void
    {
        [$organization, $user, $person] = $this->editor();

        try {
            $this->app->make(GameProfileService::class)->save($this->personContext($organization, $user), $person->id,
                new GameProfileData(['game_key' => 'valorant', 'platform_key' => 'pc', 'region_key' => 'eu', 'organization_id' => $organization->id]));
            $this->fail('Direct services must validate their own input.');
        } catch (ValidationException $exception) {
            $this->assertSame(['organization_id' => ['Ce champ ne peut pas être fourni.']], $exception->errors());
            $this->assertDatabaseCount('person_game_profiles', 0);
        }
    }

    public function test_existing_context_cannot_bypass_membership_revocation(): void
    {
        [$organization, $user, $person] = $this->editor();
        $context = $this->personContext($organization, $user);
        OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->update(['status' => 'revoked']);

        try {
            $this->app->make(GameProfileService::class)->save($context, $person->id, new GameProfileData(['game_key' => 'valorant', 'platform_key' => 'pc', 'region_key' => 'eu']));
            $this->fail('Captured contexts must recheck current rights.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('person_game_profiles', 0);
            $this->assertDatabaseCount('organization_audit_events', 0);
        }
    }

    public function test_unknown_game_region_platform_and_provider_are_refused_before_persistence(): void
    {
        [$organization, $user, $person] = $this->editor();
        $context = $this->personContext($organization, $user);
        foreach (['game_key', 'platform_key', 'region_key'] as $field) {
            try {
                $this->app->make(GameProfileService::class)->save($context, $person->id,
                    new GameProfileData([...['game_key' => 'valorant', 'platform_key' => 'pc', 'region_key' => 'eu'], $field => 'unknown']));
                $this->fail('Only configured references can establish a profile context.');
            } catch (DomainConflictException $exception) {
                $this->assertSame('GAME_CONTEXT_UNAVAILABLE', $exception->errorCode());
            }
        }
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $organization->id]);
        try {
            $this->app->make(ExternalGameAccountService::class)->save($context, $person->id, $profile->id, new ExternalGameAccountData(['provider_key' => 'unknown', 'external_id' => 'stable']));
            $this->fail('Only configured providers can be linked.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('GAME_CONTEXT_UNAVAILABLE', $exception->errorCode());
        }
        $this->assertDatabaseCount('person_external_accounts', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    /** @return array<string, array{string|null, string}> */
    public static function freshnessCases(): array
    {
        return ['unknown' => [null, 'unknown'], 'recent' => ['2026-10-10T12:00:00+00:00', 'recent'],
            'exact threshold' => ['2026-09-11T12:00:00+00:00', 'recent'],
            'stale' => ['2026-09-11T11:59:59+00:00', 'stale']];
    }

    #[DataProvider('freshnessCases')]
    public function test_freshness_uses_observation_time_and_not_the_database_update_time(?string $observation, string $expected): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11T12:00:00+00:00'));
        [$organization, $user, $person] = $this->editor();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $organization->id, 'observed_at' => $observation]);
        $account = ExternalGameAccount::factory()->create(['game_profile_id' => $profile->id, 'observed_at' => $observation]);
        $context = $this->personContext($organization, $user);
        $projection = $this->app->make(GameProfileProjectionService::class);

        $projected = $projection->profiles($context, [$this->app->make(GameProfileService::class)->find($context, $person->id, $profile->id)])[0];
        $this->assertSame($expected, $projected['freshness']);
        $this->assertSame($expected, $projection->accounts($context, [$account])[0]['freshness']);
        $this->assertSame(1, $projected['external_accounts_count']);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidFreshness(): array
    {
        return ['zero' => [0], 'null' => [null], 'string' => ['30'], 'too large' => [3651]];
    }

    #[DataProvider('invalidFreshness')]
    public function test_invalid_freshness_configuration_refuses_mutation_before_any_save(mixed $value): void
    {
        [$organization, $user, $person] = $this->editor();
        config(['game_profiles.freshness_days' => $value]);

        try {
            $this->app->make(GameProfileService::class)->save($this->personContext($organization, $user), $person->id,
                new GameProfileData(['game_key' => 'valorant', 'platform_key' => 'pc', 'region_key' => 'eu']));
            $this->fail('Misconfiguration must not fail only after a committed write.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('GAME_CATALOGUE_INVALID', $exception->errorCode());
            $this->assertDatabaseCount('person_game_profiles', 0);
            $this->assertDatabaseCount('organization_audit_events', 0);
        }
    }

    public function test_profile_limit_is_bounded_per_person_and_does_not_block_existing_profile_archival(): void
    {
        [$organization, $user, $person] = $this->editor();
        $profiles = GameProfile::factory()->count(GameProfileInput::MAX_PROFILES_PER_PERSON)
            ->sequence(fn ($sequence) => ['region_key' => 'r-'.$sequence->index])
            ->create(['person_id' => $person->id, 'organization_id' => $organization->id]);
        $context = $this->personContext($organization, $user);

        try {
            $this->app->make(GameProfileService::class)->save($context, $person->id, new GameProfileData(['game_key' => 'valorant', 'platform_key' => 'pc', 'region_key' => 'na']));
            $this->fail('New profiles must respect the per-person limit.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('GAME_PROFILE_LIMIT_REACHED', $exception->errorCode());
        }
        $profile = $profiles->firstOrFail();
        $this->app->make(GameProfileService::class)->save($context, $person->id, new GameProfileData(['status' => 'archived'], $profile->version), $profile->id);
        $this->assertDatabaseHas('person_game_profiles', ['id' => $profile->id, 'status' => 'archived']);
    }

    public function test_account_limit_is_bounded_per_profile_and_archival_preserves_reserved_identifiers(): void
    {
        [$organization, $user, $person] = $this->editor();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $organization->id]);
        $accounts = ExternalGameAccount::factory()->count(GameProfileInput::MAX_ACCOUNTS_PER_PROFILE)->create(['game_profile_id' => $profile->id]);
        $context = $this->personContext($organization, $user);

        try {
            $this->app->make(ExternalGameAccountService::class)->save($context, $person->id, $profile->id, new ExternalGameAccountData(['provider_key' => 'riot', 'external_id' => 'extra-account']));
            $this->fail('New accounts must respect the per-profile limit.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('EXTERNAL_ACCOUNT_LIMIT_REACHED', $exception->errorCode());
        }
        $account = $accounts->firstOrFail();
        $this->app->make(ExternalGameAccountService::class)->save($context, $person->id, $profile->id, new ExternalGameAccountData(['status' => 'archived'], $account->version), $account->id);
        $this->assertDatabaseHas('person_external_accounts', ['id' => $account->id, 'status' => 'archived', 'external_id' => $account->external_id]);
        $this->assertDatabaseCount('person_external_accounts', 20);
    }

    /** @return array<string, array{bool}> */
    public static function auditMutations(): array
    {
        return ['profile' => [false], 'account' => [true]];
    }

    #[DataProvider('auditMutations')]
    public function test_audit_failure_rolls_back_the_complete_business_write(bool $external): void
    {
        [$organization, $user, $person] = $this->editor();
        $profile = $external ? GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $organization->id]) : null;
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION reject_game_audit() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Audit unavailable'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER reject_game_audit BEFORE INSERT ON organization_audit_events FOR EACH ROW EXECUTE FUNCTION reject_game_audit()");
        } else {
            DB::unprepared("CREATE TRIGGER reject_game_audit BEFORE INSERT ON organization_audit_events BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
        }
        try {
            $context = $this->personContext($organization, $user);
            try {
                if ($profile !== null) {
                    $this->app->make(ExternalGameAccountService::class)->save($context, $person->id, $profile->id, new ExternalGameAccountData(['provider_key' => 'riot', 'external_id' => 'stable']));
                } else {
                    $this->app->make(GameProfileService::class)->save($context, $person->id, new GameProfileData(['game_key' => 'valorant', 'platform_key' => 'pc', 'region_key' => 'eu']));
                }
                $this->fail('The business write must fail with its audit.');
            } catch (QueryException) {
                $this->assertDatabaseCount('person_game_profiles', $external ? 1 : 0);
                $this->assertDatabaseCount('person_external_accounts', 0);
                $this->assertDatabaseCount('organization_audit_events', 0);
            }
        } finally {
            DB::unprepared('DROP TRIGGER reject_game_audit'.(DB::getDriverName() === 'pgsql' ? ' ON organization_audit_events' : ''));
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared('DROP FUNCTION reject_game_audit()');
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function cacheStores(): array
    {
        return ['memory' => ['array'], 'database' => ['database']];
    }

    #[DataProvider('cacheStores')]
    public function test_profile_page_query_count_does_not_grow_with_the_number_of_profiles(string $cacheStore): void
    {
        $this->freezeTime();
        config(['tenancy.cache_store' => $cacheStore]);
        [$organization, $user, $person] = $this->editor();
        $this->actingAsWithMfa($user);
        GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $organization->id]);
        $path = '/api/v1/tenants/'.$organization->id.'/people/'.$person->id.'/game-profiles?per_page=21';
        $this->getJson($path)->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data');
        $small = count(DB::getQueryLog());
        GameProfile::factory()->count(20)->sequence(fn ($sequence) => ['region_key' => 'r-'.$sequence->index])
            ->create(['person_id' => $person->id, 'organization_id' => $organization->id]);
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonCount(21, 'data');

        $this->assertSame($small, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    /** @return array{Organization, User, Person} */
    private function editor(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, ['sports.read', 'sports.write']);
        $person = Person::factory()->create(['organization_id' => $organization->id]);

        return [$organization, $user, $person];
    }
}
