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
        $isolationMigration->down();
        $accessMigration->down();
        $auditMigration->down();
        $organizationMigration->down();
        $this->assertFalse(Schema::hasTable('organizations'));
        $organizationMigration->up();
        $auditMigration->up();
        $accessMigration->up();
        $isolationMigration->up();
        $this->assertTrue(Schema::hasTable('organizations'));
        $this->assertTrue(Schema::hasTable('organization_settings'));
        $this->assertTrue(Schema::hasTable('organization_lifecycle_events'));
        $this->assertTrue(Schema::hasTable('organization_memberships'));
        $this->assertTrue(Schema::hasTable('organization_invitations'));
        $this->assertTrue(Schema::hasTable('organization_access_events'));
    }

    public function test_settings_cannot_reference_a_nonexistent_organization(): void
    {
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('organization_settings')->insert([
            'organization_id' => '6602d0d2-b528-4472-b1a7-c3b7b7f454ae',
        ]));
    }
}
