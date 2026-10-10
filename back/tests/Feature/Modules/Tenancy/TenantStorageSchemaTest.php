<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantStorageSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string}> */
    public static function tables(): array
    {
        return ['usage' => ['tenant_storage_usage'], 'artifacts' => ['tenant_artifacts']];
    }

    #[DataProvider('tables')]
    public function test_database_forbids_reassigning_usage_or_artifacts_to_another_tenant(string $table): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $this->insert($table, $first->id);

        try {
            DB::transaction(fn () => DB::table($table)->where('organization_id', $first->id)->update(['organization_id' => $second->id]));
            $this->fail('Resource accounting must not be reassigned across tenants.');
        } catch (QueryException) {
            $this->assertDatabaseHas($table, ['organization_id' => $first->id, 'bytes' => 3]);
            $this->assertDatabaseMissing($table, ['organization_id' => $second->id]);
        }
    }

    #[DataProvider('tables')]
    public function test_database_rejects_negative_resource_accounting(string $table): void
    {
        $organization = Organization::factory()->create();
        $this->insert($table, $organization->id);

        try {
            DB::transaction(fn () => DB::table($table)->where('organization_id', $organization->id)->update(['bytes' => -1]));
            $this->fail('Negative quota usage must be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseHas($table, ['organization_id' => $organization->id, 'bytes' => 3]);
        }
    }

    #[DataProvider('tables')]
    public function test_database_rejects_resource_records_without_an_existing_organization(string $table): void
    {
        $this->expectException(QueryException::class);
        DB::transaction(fn () => $this->insert($table, (string) Str::uuid()));
    }

    public function test_new_storage_migration_can_be_reversed_and_reapplied_without_touching_existing_tenants(): void
    {
        $organization = Organization::factory()->create();
        $migration = require database_path('migrations/2026_10_10_160242_create_tenant_artifact_ledger_tables.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('tenant_artifacts'));
        $this->assertFalse(Schema::hasTable('tenant_storage_usage'));
        $migration->up();

        $this->assertTrue(Schema::hasTable('tenant_artifacts'));
        $this->assertTrue(Schema::hasTable('tenant_storage_usage'));
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'slug' => $organization->slug]);
    }

    private function insert(string $table, string $organizationId): void
    {
        $attributes = ['organization_id' => $organizationId, 'bytes' => 3];
        if ($table === 'tenant_artifacts') {
            $attributes += ['id' => (string) Str::uuid(), 'name' => 'report.txt', 'disk' => 'local', 'ready' => false, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table($table)->insert($attributes);
    }
}
