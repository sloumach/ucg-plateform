<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Exceptions\DomainConflictException;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersonSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_nullable_accounts_allow_multiple_people_and_tenant_constraints_remain_valid(): void
    {
        $organization = Organization::factory()->create();
        Person::factory()->count(2)->create(['organization_id' => $organization->id, 'pseudonym' => 'Même pseudo']);

        $this->assertDatabaseCount('people', 2);
        $this->assertSame([], $this->app->make(TenantSchemaInspector::class)->violations());
    }

    /** @return array<string, array{string}> */
    public static function immutableColumns(): array
    {
        return ['identifier' => ['id'], 'organization' => ['organization_id']];
    }

    #[DataProvider('immutableColumns')]
    public function test_database_rejects_bulk_identity_changes(string $column): void
    {
        $person = Person::factory()->create();
        $other = Organization::factory()->create();
        $value = $column === 'id' ? (string) Str::uuid() : $other->id;

        try {
            DB::transaction(fn () => DB::table('people')->where('id', $person->id)->update([$column => $value]));
            $this->fail('Person identity must be protected in SQL.');
        } catch (QueryException) {
            $this->assertDatabaseHas('people', ['id' => $person->id, 'organization_id' => $person->organization_id]);
        }
    }

    public function test_eloquent_rejects_moving_a_person_to_another_tenant(): void
    {
        $person = Person::factory()->create();
        $other = Organization::factory()->create();

        $this->expectException(DomainConflictException::class);
        $person->forceFill(['organization_id' => $other->id])->save();
    }

    public function test_database_rejects_duplicate_account_link_in_one_tenant(): void
    {
        $organization = Organization::factory()->create();
        Person::factory()->create(['organization_id' => $organization->id, 'user_id' => $organization->owner_user_id]);

        try {
            DB::transaction(fn () => Person::factory()->create(['organization_id' => $organization->id, 'user_id' => $organization->owner_user_id]));
            $this->fail('Duplicate linked accounts must be protected in SQL.');
        } catch (QueryException) {
            $this->assertDatabaseCount('people', 1);
        }
    }

    public function test_database_rejects_invalid_status_and_missing_account(): void
    {
        $person = Person::factory()->create();
        foreach ([['status' => 'administrator'], ['user_id' => 99999999]] as $change) {
            try {
                DB::transaction(fn () => DB::table('people')->where('id', $person->id)->update($change));
                $this->fail('Person constraints must apply to bulk SQL updates.');
            } catch (QueryException) {
                $this->assertDatabaseHas('people', ['id' => $person->id, 'status' => 'active', 'user_id' => null]);
            }
        }
    }

    public function test_physical_deletion_is_refused_in_sql_and_preserves_history(): void
    {
        $person = Person::factory()->create();

        try {
            DB::transaction(fn () => DB::table('people')->where('id', $person->id)->delete());
            $this->fail('Physical deletion must require a separate retention procedure.');
        } catch (QueryException) {
            $this->assertModelExists($person);
        }
    }

    public function test_eloquent_refuses_physical_deletion(): void
    {
        $person = Person::factory()->create();

        $this->expectException(DomainConflictException::class);
        $person->delete();
    }

    public function test_migration_can_be_reversed_empty_and_reapplied_in_the_test_database(): void
    {
        $this->assertTrue(Schema::hasTable('people'));
        $migration = require database_path('migrations/2026_10_11_215159_create_people_table.php');

        $profilesMigration = require database_path('migrations/2026_10_11_223914_create_person_game_profiles_and_external_accounts.php');
        $lifecycleMigration = require database_path('migrations/2026_10_12_173809_create_person_lifecycle_tables.php');
        $importMigration = require database_path('migrations/2026_10_12_211057_create_person_import_tables.php');
        $importMigration->down();
        $lifecycleMigration->down();
        $profilesMigration->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('people'));
        $migration->up();
        $profilesMigration->up();
        $lifecycleMigration->up();
        $importMigration->up();

        $this->assertTrue(Schema::hasTable('people'));
        $this->assertDatabaseCount('people', 0);
    }

    public function test_rollback_refuses_to_discard_existing_person_records(): void
    {
        $person = Person::factory()->create();
        $migration = require database_path('migrations/2026_10_11_215159_create_people_table.php');

        try {
            $migration->down();
            $this->fail('Rollback must not discard person records.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot discard person records', $exception->getMessage());
            $this->assertModelExists($person);
        }
    }

    public function test_operator_portability_includes_only_own_people_and_preserves_encrypted_fields(): void
    {
        $person = Person::factory()->withAdministrativeIdentity()->create();
        Person::factory()->withAdministrativeIdentity()->create();
        $rows = iterator_to_array($this->app->make(PortabilityRepository::class)->rows($person->organization_id, 100));
        $people = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'people'));

        $this->assertCount(1, $people);
        $this->assertSame($person->id, $people[0]['data']['id']);
        $this->assertSame($person->organization_id, $people[0]['data']['organization_id']);
        $this->assertSame($person->getRawOriginal('first_name'), $people[0]['data']['first_name']);
        $this->assertNotSame('Camille', $people[0]['data']['first_name']);
        $this->assertNotContains('users', array_column($rows, 'table'));
    }
}
