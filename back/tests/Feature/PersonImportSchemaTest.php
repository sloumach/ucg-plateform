<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\People\Domain\Models\PersonImportRow;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonImportSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sql_preserves_created_line_and_refuses_confirmed_history_deletion(): void
    {
        $run = PersonImport::factory()->create();
        $person = Person::factory()->create(['organization_id' => $run->organization_id]);
        $row = new PersonImportRow;
        $row->forceFill(['organization_id' => $run->organization_id, 'import_id' => $run->id, 'line' => 2,
            'status' => 'created', 'person_id' => $person->id, 'values' => ['pseudonym' => 'SECRET'], 'errors' => []])->save();
        $run->forceFill(['status' => 'completed', 'execution_actor_id' => $run->actor_user_id, 'confirmed_version' => $run->version])->save();
        foreach (['delete', 'status', 'line', 'values'] as $operation) {
            try {
                DB::transaction(function () use ($operation, $row): void {
                    $query = DB::table('person_import_rows')->where('id', $row->id);
                    if ($operation === 'delete') {
                        $query->delete();
                    } else {
                        $query->update([$operation => match ($operation) {
                            'line' => 3, 'status' => 'ready', default => 'corrupted'
                        }]);
                    }
                });
                $this->fail('Created history must be retained.');
            } catch (QueryException) {
                $this->assertDatabaseHas('person_import_rows', ['id' => $row->id, 'status' => 'created', 'line' => 2]);
            }
        }
        try {
            DB::transaction(fn () => $run->forceFill(['mapping' => ['pseudonym' => 1]])->save());
            $this->fail('Confirmed mapping must be immutable.');
        } catch (QueryException) {
            $this->assertSame([], $run->refresh()->mapping);
        }
    }

    public function test_operator_backup_keeps_import_values_encrypted_and_tenant_scoped(): void
    {
        $run = PersonImport::factory()->create(['headers' => ['SECRET_HEADER']]);
        PersonImport::factory()->create(['headers' => ['OTHER_SECRET']]);
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($run->organization_id, 1000));
        $imports = array_values(array_filter($rows, fn ($row) => $row['table'] === 'person_imports'));
        $this->assertCount(1, $imports);
        $this->assertSame($run->id, $imports[0]['data']['id']);
        $this->assertStringNotContainsString('SECRET_HEADER', json_encode($rows, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('OTHER_SECRET', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function test_tenant_schema_and_backup_inventory_include_import_tables(): void
    {
        $run = PersonImport::factory()->create();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
        $this->assertDatabaseHas('person_imports', ['organization_id' => $run->organization_id, 'id' => $run->id]);
    }

    public function test_sql_rejects_cross_tenant_artifact_reference(): void
    {
        $run = PersonImport::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => PersonImport::factory()->create(['artifact_id' => $run->artifact_id]));
    }

    public function test_sql_rejects_import_identity_changes(): void
    {
        $run = PersonImport::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('person_imports')->where('id', $run->id)->update(['organization_id' => (string) Str::uuid()]));
    }

    public function test_rollback_refuses_nonempty_import_history(): void
    {
        PersonImport::factory()->create();
        $migration = require database_path('migrations/2026_10_12_211057_create_person_import_tables.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_empty_rollback_and_reapply_preserve_the_tenant_schema(): void
    {
        $migration = require database_path('migrations/2026_10_12_211057_create_person_import_tables.php');
        $documents = require database_path('migrations/2026_10_14_193828_create_contract_documents_and_immutable_versions.php');
        $scans = require database_path('migrations/2026_10_14_204339_create_contract_document_scans.php');
        $governance = require database_path('migrations/2026_10_14_231650_add_contract_financial_governance.php');
        $reminders = require database_path('migrations/2026_10_15_184218_create_contract_reminders.php');
        $reminders->down();
        $governance->down();
        $scans->down();
        $documents->down();
        $statImports = require database_path('migrations/2026_10_19_143154_create_statistical_import_previews.php');
        $statisticalExecution = require database_path('migrations/2026_10_19_175141_create_statistical_import_executions.php');
        $statisticalCalculations = require database_path('migrations/2026_10_19_183908_create_statistical_calculations.php');
        $statisticalCalculations->down();
        $statisticalExecution->down();
        $statImports->down();
        $migration->down();
        $migration->up();
        $statImports->up();
        $statisticalExecution->up();
        $statisticalCalculations->up();
        $documents->up();
        $scans->up();
        $governance->up();
        $reminders->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }
}
