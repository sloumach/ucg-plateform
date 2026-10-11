<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Exceptions\DomainConflictException;
use App\Modules\People\Domain\Models\ExternalGameAccount;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameProfileSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_profile_cannot_reference_a_person_from_another_existing_tenant(): void
    {
        $person = Person::factory()->create();
        $other = Organization::factory()->create();

        $this->reject(fn () => GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $other->id]), '23503');
        $this->assertDatabaseCount('person_game_profiles', 0);
    }

    public function test_account_cannot_reference_a_foreign_tenant_profile(): void
    {
        $profile = GameProfile::factory()->create();
        $other = Organization::factory()->create();

        $this->reject(fn () => ExternalGameAccount::factory()->create(['game_profile_id' => $profile->id, 'organization_id' => $other->id]), '23503');
        $this->assertDatabaseCount('person_external_accounts', 0);
    }

    /** @return array<string, array{string}> */
    public static function contextColumns(): array
    {
        return ['game' => ['game_key'], 'platform' => ['platform_key'], 'region' => ['region_key']];
    }

    #[DataProvider('contextColumns')]
    public function test_account_must_match_the_full_context_of_its_parent_profile(string $column): void
    {
        $profile = GameProfile::factory()->create();

        $this->reject(fn () => ExternalGameAccount::factory()->create(['game_profile_id' => $profile->id, $column => 'foreign-context']), '23503');
        $this->assertDatabaseCount('person_external_accounts', 0);
    }

    public function test_profile_uniqueness_is_per_person_and_tenant(): void
    {
        $profile = GameProfile::factory()->create();

        $this->reject(fn () => GameProfile::factory()->create(['person_id' => $profile->person_id, 'organization_id' => $profile->organization_id]), '23505');
        $this->assertDatabaseCount('person_game_profiles', 1);
    }

    public function test_stable_external_ids_are_reserved_after_archival_but_not_across_tenants(): void
    {
        $account = ExternalGameAccount::factory()->create(['external_id' => 'Same-Stable-ID', 'status' => 'archived']);
        $profile = GameProfile::factory()->create(['organization_id' => $account->organization_id,
            'person_id' => Person::factory()->create(['organization_id' => $account->organization_id])->id]);

        $this->reject(fn () => ExternalGameAccount::factory()->create(['game_profile_id' => $profile->id, 'external_id' => 'Same-Stable-ID']), '23505');
        ExternalGameAccount::factory()->create(['external_id' => 'Same-Stable-ID']);
        $this->assertDatabaseCount('person_external_accounts', 2);
    }

    public function test_null_stable_ids_allow_duplicate_aliases_without_automatic_reconciliation(): void
    {
        $profile = GameProfile::factory()->create();
        ExternalGameAccount::factory()->count(2)->create(['game_profile_id' => $profile->id, 'external_id' => null, 'display_name' => 'Même nom']);

        $this->assertDatabaseCount('person_external_accounts', 2);
        $this->assertSame([], $this->app->make(TenantSchemaInspector::class)->violations());
    }

    /** @return array<string, array{string}> */
    public static function profileIdentityColumns(): array
    {
        return ['id' => ['id'], 'organization' => ['organization_id'], 'person' => ['person_id'],
            'game' => ['game_key'], 'platform' => ['platform_key'], 'region' => ['region_key']];
    }

    #[DataProvider('profileIdentityColumns')]
    public function test_bulk_sql_cannot_change_profile_identity(string $column): void
    {
        $profile = GameProfile::factory()->create();
        $value = in_array($column, ['id', 'organization_id', 'person_id'], true) ? (string) Str::uuid() : 'changed';
        $this->reject(fn () => DB::table('person_game_profiles')->where('id', $profile->id)->update([$column => $value]), '23514');

        $this->assertDatabaseHas('person_game_profiles', ['id' => $profile->id, 'organization_id' => $profile->organization_id]);
    }

    /** @return array<string, array{string}> */
    public static function accountIdentityColumns(): array
    {
        return ['id' => ['id'], 'organization' => ['organization_id'], 'profile' => ['game_profile_id'], 'game' => ['game_key'],
            'platform' => ['platform_key'], 'region' => ['region_key'], 'provider' => ['provider_key'], 'stable identifier' => ['external_id']];
    }

    #[DataProvider('accountIdentityColumns')]
    public function test_bulk_sql_cannot_replace_an_established_external_identity(string $column): void
    {
        $account = ExternalGameAccount::factory()->create();
        $value = in_array($column, ['id', 'organization_id', 'game_profile_id'], true) ? (string) Str::uuid() : 'changed';
        $this->reject(fn () => DB::table('person_external_accounts')->where('id', $account->id)->update([$column => $value]), '23514');

        $this->assertDatabaseHas('person_external_accounts', ['id' => $account->id, 'external_id' => $account->external_id]);
    }

    public function test_sql_allows_initial_stable_identifier_enrichment_and_then_protects_it(): void
    {
        $account = ExternalGameAccount::factory()->create(['external_id' => null]);

        DB::table('person_external_accounts')->where('id', $account->id)->update(['external_id' => 'Established-ID']);
        $this->reject(fn () => DB::table('person_external_accounts')->where('id', $account->id)->update(['external_id' => null]), '23514');
        $this->assertDatabaseHas('person_external_accounts', ['id' => $account->id, 'external_id' => 'Established-ID']);
    }

    public function test_sql_rejects_empty_external_identity_and_invalid_stable_id(): void
    {
        $profile = GameProfile::factory()->create();

        $this->reject(fn () => ExternalGameAccount::factory()->create(['game_profile_id' => $profile->id, 'external_id' => null, 'display_name' => null]), '23514');
        $this->reject(fn () => ExternalGameAccount::factory()->create(['game_profile_id' => $profile->id, 'external_id' => 'id with spaces']), '23514');
        $this->assertDatabaseCount('person_external_accounts', 0);
    }

    public function test_both_profile_and_external_account_physical_deletion_are_refused(): void
    {
        $account = ExternalGameAccount::factory()->create();
        $profile = GameProfile::findOrFail($account->game_profile_id);

        $this->reject(fn () => DB::table('person_external_accounts')->where('id', $account->id)->delete(), '23514');
        $this->reject(fn () => DB::table('person_game_profiles')->where('id', $profile->id)->delete(), '23514');
        $this->assertModelExists($account);
        $this->assertModelExists($profile);
    }

    public function test_eloquent_refuses_established_identity_changes(): void
    {
        $account = ExternalGameAccount::factory()->create();

        $this->expectException(DomainConflictException::class);
        $account->forceFill(['external_id' => 'replaced'])->save();
    }

    public function test_migration_can_be_reversed_empty_and_reapplied_without_changing_people(): void
    {
        $person = Person::factory()->create();
        $migration = require database_path('migrations/2026_10_11_223914_create_person_game_profiles_and_external_accounts.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('person_game_profiles'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('person_external_accounts'));
        $this->assertModelExists($person);
    }

    public function test_rollback_refuses_to_discard_existing_game_profiles(): void
    {
        $profile = GameProfile::factory()->create();
        $migration = require database_path('migrations/2026_10_11_223914_create_person_game_profiles_and_external_accounts.php');

        try {
            $migration->down();
            $this->fail('Rollback must preserve profiles.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot discard game profiles', $exception->getMessage());
            $this->assertModelExists($profile);
        }
    }

    public function test_operator_backup_contains_only_this_tenant_profiles_and_external_accounts(): void
    {
        $account = ExternalGameAccount::factory()->create();
        ExternalGameAccount::factory()->create();
        $rows = iterator_to_array($this->app->make(PortabilityRepository::class)->rows($account->organization_id, 100));
        $profiles = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'person_game_profiles'));
        $accounts = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'person_external_accounts'));

        $this->assertCount(1, $profiles);
        $this->assertCount(1, $accounts);
        $this->assertSame($account->game_profile_id, $profiles[0]['data']['id']);
        $this->assertSame($account->id, $accounts[0]['data']['id']);
        $this->assertSame($account->organization_id, $accounts[0]['data']['organization_id']);
        $this->assertNotContains('users', array_column($rows, 'table'));
    }

    private function reject(Closure $operation, string $sqlState): void
    {
        try {
            DB::transaction(function () use ($operation): void {
                $operation();
            });
            $this->fail('The database must reject this operation.');
        } catch (QueryException $exception) {
            $errorInfo = $exception->errorInfo;
            $this->assertNotNull($errorInfo);
            $this->assertArrayHasKey(0, $errorInfo);
            if (DB::getDriverName() === 'pgsql') {
                $this->assertSame($sqlState, $errorInfo[0]);
            } else {
                $this->assertSame('23000', $errorInfo[0]);
            }
        }
    }
}
