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
        $memberships = require database_path('migrations/2026_10_13_152226_create_team_membership_and_movement_tables.php');
        $lineups = require database_path('migrations/2026_10_13_204834_create_team_lineup_tables.php');
        $eligibility = require database_path('migrations/2026_10_13_215251_create_team_eligibility_rules_and_reports.php');
        $contracts = require database_path('migrations/2026_10_14_184245_create_contract_records_and_lifecycle.php');
        $documents = require database_path('migrations/2026_10_14_193828_create_contract_documents_and_immutable_versions.php');
        $scans = require database_path('migrations/2026_10_14_204339_create_contract_document_scans.php');
        $governance = require database_path('migrations/2026_10_14_231650_add_contract_financial_governance.php');
        $reminders = require database_path('migrations/2026_10_15_184218_create_contract_reminders.php');
        $agreements = require database_path('migrations/2026_10_16_000918_create_sponsorship_agreements_and_exposures.php');
        $campaigns = require database_path('migrations/2026_10_16_013526_create_sponsorship_campaigns_and_deliverables.php');
        $calendar = require database_path('migrations/2026_10_17_094942_create_calendar_activities_and_participants.php');
        $availability = require database_path('migrations/2026_10_17_105547_create_availabilities_and_immutable_revisions.php');
        $availability->down();
        $bookings = require database_path('migrations/2026_10_17_112802_create_calendar_resources_reservations_and_conflict_overrides.php');
        $invitations = require database_path('migrations/2026_10_17_153712_create_calendar_invitations_and_deliveries.php');
        $changes = require database_path('migrations/2026_10_17_220751_create_calendar_changes_and_recipients.php');
        $matches = require database_path('migrations/2026_10_17_225945_create_matches_participants_and_status_events.php');
        $matchLineups = require database_path('migrations/2026_10_18_101019_create_match_lineup_revisions.php');
        $matchLineups->down();
        $matches->down();
        $changes->down();
        $invitations->down();
        $bookings->down();
        $calendar->down();
        $campaigns->down();
        $agreements->down();
        $reminders->down();
        $governance->down();
        $scans->down();
        $documents->down();
        $contracts->down();
        $eligibility->down();
        $lineups->down();
        $memberships->down();
        $importMigration->down();
        $lifecycleMigration->down();
        $profilesMigration->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('people'));
        $migration->up();
        $profilesMigration->up();
        $lifecycleMigration->up();
        $importMigration->up();
        $memberships->up();
        $lineups->up();
        $eligibility->up();
        $contracts->up();
        $documents->up();
        $scans->up();
        $governance->up();
        $reminders->up();
        $agreements->up();
        $campaigns->up();
        $calendar->up();
        $bookings->up();
        $invitations->up();
        $changes->up();
        $matches->up();
        $matchLineups->up();
        $availability->up();

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
