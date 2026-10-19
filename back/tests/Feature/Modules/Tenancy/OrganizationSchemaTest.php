<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Data\OrganizationDetailsData;
use App\Modules\Tenancy\Application\Services\OrganizationService;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string, mixed}> */
    public static function immutableIdentity(): array
    {
        return ['slug' => ['slug', 'changed-slug'],
            'identifier' => ['id', '6602d0d2-b528-4472-b1a7-c3b7b7f454ae']];
    }

    #[DataProvider('immutableIdentity')]
    public function test_bulk_updates_cannot_change_organization_identity(string $column, mixed $value): void
    {
        $organization = Organization::factory()->create();
        try {
            DB::transaction(fn () => DB::table('organizations')->where('id', $organization->id)->update([$column => $value]));
            $this->fail('Immutable identity must be protected in the database.');
        } catch (QueryException) {
            $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'slug' => $organization->slug, 'owner_user_id' => $organization->owner_user_id]);
        }
    }

    public function test_database_rejects_unknown_status(): void
    {
        $organization = Organization::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('organizations')->where('id', $organization->id)->update(['status' => 'unknown']));
    }

    public function test_bulk_updates_cannot_transfer_ownership_to_another_existing_account(): void
    {
        $organization = Organization::factory()->create();
        $otherOwner = User::factory()->create();

        try {
            DB::transaction(fn () => DB::table('organizations')->where('id', $organization->id)->update(['owner_user_id' => $otherOwner->id]));
            $this->fail('Ownership transfer must not bypass the dedicated procedure.');
        } catch (QueryException) {
            $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'owner_user_id' => $organization->owner_user_id]);
        }
    }

    public function test_audit_rows_cannot_be_updated_or_deleted_and_block_physical_deletion(): void
    {
        $owner = User::factory()->create();
        $organization = app(OrganizationService::class)->create('audit-guard', $owner->id,
            new OrganizationDetailsData('Audit guard', 'UTC', 'fr', 'FR'),
            new OrganizationAuditData($owner->id, 'Test audit', 'a646bd57-c6df-47a1-8073-5aa4d89a7cbc'));
        foreach ([
            fn () => DB::table('organization_lifecycle_events')->update(['reason' => 'rewritten']),
            fn () => DB::table('organization_lifecycle_events')->delete(),
            fn () => DB::table('organizations')->where('id', $organization->id)->delete(),
            fn () => DB::table('users')->where('id', $owner->id)->delete(),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Protected historical data must not be deleted or rewritten.');
            } catch (QueryException) {
                $this->assertDatabaseHas('organization_lifecycle_events', ['organization_id' => $organization->id, 'reason' => 'Test audit']);
            }
        }
    }

    public function test_settings_require_an_existing_organization_and_only_one_row_per_organization(): void
    {
        $organization = Organization::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('organization_settings')->insert(['organization_id' => $organization->id]));
    }

    public function test_migrations_can_be_reversed_and_reapplied_in_the_test_database(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $organizationMigration = require database_path('migrations/2026_10_10_085921_create_organizations_and_settings_tables.php');
        $auditMigration = require database_path('migrations/2026_10_10_085922_create_organization_lifecycle_events_table.php');
        $accessMigration = require database_path('migrations/2026_10_10_122134_create_organization_memberships_invitations_and_access_events.php');
        $isolationMigration = require database_path('migrations/2026_10_10_152734_enforce_tenant_database_isolation.php');
        $storageMigration = require database_path('migrations/2026_10_10_160242_create_tenant_artifact_ledger_tables.php');
        $portabilityMigration = require database_path('migrations/2026_10_10_204152_create_tenant_portability_operations_table.php');
        $rolesMigration = require database_path('migrations/2026_10_11_120303_create_organization_roles_and_role_assignments.php');
        $supportMigration = require database_path('migrations/2026_10_11_183828_create_platform_roles_and_support_accesses.php');
        $transverseAuditMigration = require database_path('migrations/2026_10_11_203439_create_transverse_audit_events.php');
        $peopleMigration = require database_path('migrations/2026_10_11_215159_create_people_table.php');
        $profilesMigration = require database_path('migrations/2026_10_11_223914_create_person_game_profiles_and_external_accounts.php');
        $lifecycleMigration = require database_path('migrations/2026_10_12_173809_create_person_lifecycle_tables.php');
        $importMigration = require database_path('migrations/2026_10_12_211057_create_person_import_tables.php');
        $catalogueMigration = require database_path('migrations/2026_10_13_120201_create_game_catalogue_and_organization_games.php');
        $teamsMigration = require database_path('migrations/2026_10_13_143631_create_teams_table.php');
        $memberships = require database_path('migrations/2026_10_13_152226_create_team_membership_and_movement_tables.php');
        $lineups = require database_path('migrations/2026_10_13_204834_create_team_lineup_tables.php');
        $eligibility = require database_path('migrations/2026_10_13_215251_create_team_eligibility_rules_and_reports.php');
        $opponents = require database_path('migrations/2026_10_14_121838_create_external_opponents_and_snapshots.php');
        $matches = require database_path('migrations/2026_10_17_225945_create_matches_participants_and_status_events.php');
        $matchLineups = require database_path('migrations/2026_10_18_101019_create_match_lineup_revisions.php');
        $matchScores = require database_path('migrations/2026_10_18_135008_create_match_score_rules_and_game_instances.php');
        $matchResults = require database_path('migrations/2026_10_18_144624_create_match_result_events_and_guards.php');
        $notebook = require database_path('migrations/2026_10_18_155140_create_match_notebook_revisions.php');
        $competitions = require database_path('migrations/2026_10_18_210127_create_external_competitions_and_private_documents.php');
        $registrations = require database_path('migrations/2026_10_18_231153_create_competition_registration_tracking.php');
        $outcomes = require database_path('migrations/2026_10_19_005858_create_competition_outcome_tracking.php');
        $metrics = require database_path('migrations/2026_10_19_094551_create_metric_dictionary.php');
        $observations = require database_path('migrations/2026_10_19_113618_create_statistical_observations.php');
        $statImports = require database_path('migrations/2026_10_19_143154_create_statistical_import_previews.php');
        $statImports->down();
        $observations->down();
        $metrics->down();
        $outcomes->down();
        $registrations->down();
        $competitions->down();
        $notebook->down();
        $corrections = require database_path('migrations/2026_10_18_184341_create_match_score_corrections_and_recalculations.php');
        $corrections->down();
        $matchResults->down();
        $matchScores->down();
        $matchLineups->down();
        $matches->down();
        $opponents->down();
        $contracts = require database_path('migrations/2026_10_14_184245_create_contract_records_and_lifecycle.php');
        $documents = require database_path('migrations/2026_10_14_193828_create_contract_documents_and_immutable_versions.php');
        $scans = require database_path('migrations/2026_10_14_204339_create_contract_document_scans.php');
        $governance = require database_path('migrations/2026_10_14_231650_add_contract_financial_governance.php');
        $reminders = require database_path('migrations/2026_10_15_184218_create_contract_reminders.php');
        $sensitiveAudit = require database_path('migrations/2026_10_15_192732_create_organization_sensitive_access_events.php');
        $partnerships = require database_path('migrations/2026_10_15_211008_create_partnership_prospects_and_contacts.php');
        $agreements = require database_path('migrations/2026_10_16_000918_create_sponsorship_agreements_and_exposures.php');
        $campaigns = require database_path('migrations/2026_10_16_013526_create_sponsorship_campaigns_and_deliverables.php');
        $media = require database_path('migrations/2026_10_16_192806_create_media_items_table.php');
        $communication = require database_path('migrations/2026_10_16_210818_create_communication_tables.php');
        $calendar = require database_path('migrations/2026_10_17_094942_create_calendar_activities_and_participants.php');
        $availability = require database_path('migrations/2026_10_17_105547_create_availabilities_and_immutable_revisions.php');
        $availability->down();
        $bookings = require database_path('migrations/2026_10_17_112802_create_calendar_resources_reservations_and_conflict_overrides.php');
        $invitations = require database_path('migrations/2026_10_17_153712_create_calendar_invitations_and_deliveries.php');
        $changes = require database_path('migrations/2026_10_17_220751_create_calendar_changes_and_recipients.php');
        $changes->down();
        $this->assertFalse(Schema::hasTable('calendar_changes'));
        $this->assertFalse(Schema::hasTable('calendar_change_recipients'));
        $this->assertFalse(Schema::hasColumn('calendar_activities', 'cancelled_at'));
        $invitations->down();
        $feeds = require database_path('migrations/2026_10_17_164002_create_calendar_feeds_table.php');
        $feeds->down();
        $bookings->down();
        $calendar->down();
        $communication->down();
        $media->down();
        $campaigns->down();
        $agreements->down();
        $partnerships->down();
        $sensitiveAudit->down();
        $reminders->down();
        $governance->down();
        $scans->down();
        $documents->down();
        $contracts->down();
        $eligibility->down();
        $lineups->down();
        $memberships->down();
        $teamsMigration->down();
        $catalogueMigration->down();
        $importMigration->down();
        $lifecycleMigration->down();
        $profilesMigration->down();
        $peopleMigration->down();
        $transverseAuditMigration->down();
        $supportMigration->down();
        $rolesMigration->down();
        $portabilityMigration->down();
        $storageMigration->down();
        $isolationMigration->down();
        $accessMigration->down();
        $auditMigration->down();
        $organizationMigration->down();
        $this->assertFalse(Schema::hasTable('organizations'));
        $this->assertFalse(Schema::hasTable('calendar_feeds'));
        $this->assertFalse(Schema::hasTable('calendar_invitations'));
        $this->assertFalse(Schema::hasTable('calendar_invitation_deliveries'));
        $organizationMigration->up();
        $auditMigration->up();
        $accessMigration->up();
        $isolationMigration->up();
        $storageMigration->up();
        $portabilityMigration->up();
        $rolesMigration->up();
        $supportMigration->up();
        $transverseAuditMigration->up();
        $peopleMigration->up();
        $profilesMigration->up();
        $lifecycleMigration->up();
        $importMigration->up();
        $catalogueMigration->up();
        $teamsMigration->up();
        $memberships->up();
        $lineups->up();
        $eligibility->up();
        $contracts->up();
        $documents->up();
        $scans->up();
        $governance->up();
        $reminders->up();
        $sensitiveAudit->up();
        $opponents->up();
        $partnerships->up();
        $agreements->up();
        $campaigns->up();
        $media->up();
        $communication->up();
        $calendar->up();
        $bookings->up();
        $availability->up();
        $invitations->up();
        $changes->up();
        $this->assertTrue(Schema::hasTable('calendar_changes'));
        $this->assertTrue(Schema::hasTable('calendar_change_recipients'));
        $this->assertTrue(Schema::hasColumn('calendar_activities', 'cancelled_at'));
        $feeds->up();
        $matches->up();
        $matchLineups->up();
        $matchScores->up();
        $matchResults->up();
        $corrections->up();
        $notebook->up();
        $competitions->up();
        $registrations->up();
        $outcomes->up();
        $metrics->up();
        $observations->up();
        $statImports->up();
        $this->assertTrue(Schema::hasTable('calendar_feeds'));
        $this->assertTrue(Schema::hasTable('organizations'));
        $this->assertTrue(Schema::hasTable('organization_settings'));
        $this->assertTrue(Schema::hasTable('organization_lifecycle_events'));
        $this->assertTrue(Schema::hasTable('organization_memberships'));
        $this->assertTrue(Schema::hasTable('organization_invitations'));
        $this->assertTrue(Schema::hasTable('organization_access_events'));
        $this->assertTrue(Schema::hasTable('tenant_storage_usage'));
        $this->assertTrue(Schema::hasTable('tenant_artifacts'));
        $this->assertTrue(Schema::hasTable('tenant_portability_operations'));
        $this->assertTrue(Schema::hasTable('organization_roles'));
        $this->assertTrue(Schema::hasTable('organization_role_assignments'));
        $this->assertTrue(Schema::hasTable('platform_roles'));
        $this->assertTrue(Schema::hasTable('platform_role_events'));
        $this->assertTrue(Schema::hasTable('organization_support_accesses'));
        $this->assertTrue(Schema::hasTable('organization_support_events'));
        $this->assertTrue(Schema::hasTable('calendar_invitations'));
        $this->assertTrue(Schema::hasTable('calendar_invitation_deliveries'));
    }

    public function test_settings_cannot_reference_a_nonexistent_organization(): void
    {
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('organization_settings')->insert([
            'organization_id' => '6602d0d2-b528-4472-b1a7-c3b7b7f454ae',
        ]));
    }
}
