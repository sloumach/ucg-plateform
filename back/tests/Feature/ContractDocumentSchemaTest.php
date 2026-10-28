<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Contracts\Application\Services\ContractDocumentService;
use App\Modules\Contracts\Application\Services\ContractService;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\ProvidesContractDocuments;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ContractDocumentSchemaTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesContractDocuments, ProvidesPeopleAccess;

    public function test_schema_and_export_catalog_include_document_versions_and_bindings(): void
    {
        $this->assertDatabaseCount('contract_documents', 0);
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    /** @return array<string, array{string, string}> */
    public static function immutableRows(): array
    {
        return ['document update' => ['contract_documents', 'update'], 'document delete' => ['contract_documents', 'delete'],
            'version update' => ['contract_document_versions', 'update'], 'version delete' => ['contract_document_versions', 'delete'],
            'binding update' => ['contract_document_signatures', 'update'], 'binding delete' => ['contract_document_signatures', 'delete']];
    }

    #[DataProvider('immutableRows')]
    public function test_sql_cannot_rewrite_or_remove_documents_versions_or_signed_binding(string $table, string $operation): void
    {
        Storage::fake('local');
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        [, $validator] = $this->declareContractSignature($org, $contract, $context);
        $service = app(ContractDocumentService::class);
        $version = $service->upload($validator, $contract->id, $document->id,
            ['contract_version' => $contract->version, 'purpose' => 'signed_copy', 'file' => $this->documentPdf()])['version'];
        $contract->refresh();
        $service->sign($validator, $contract->id, $document->id, ['contract_version' => $contract->version,
            'document_version_id' => $version->id, 'signed_on' => now()->utc()->toDateString(), 'confirm_signed_copy' => true]);
        $this->reject(fn () => $operation === 'delete' ? DB::table($table)->where('contract_id', $contract->id)->delete()
            : DB::table($table)->where('contract_id', $contract->id)->update(['id' => (string) Str::uuid()]));
        $this->assertDatabaseCount($table, 1);
    }

    public function test_sql_rejects_version_with_foreign_contract_document_or_artifact(): void
    {
        Storage::fake('local');
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        $version = app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
            ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf()])['version'];
        $attributes = $version->getRawOriginal();
        unset($attributes['id']);
        $foreign = ContractDocument::factory()->create();
        $freshArtifact = app(TenantArtifacts::class)->store($context, 'fk-test.enc', encrypt('safe', false));
        $this->reject(fn () => DB::table('contract_document_versions')->insert([...$attributes, 'id' => (string) Str::uuid(), 'number' => 1,
            'artifact_id' => $freshArtifact->id,
            'document_id' => $foreign->id, 'contract_id' => $foreign->contract_id]), '23503');
        $this->assertDatabaseCount('contract_document_versions', 1);
        // A ready artifact from the other tenant still cannot satisfy the composite FK.
        [$other, , $otherContract, $otherContext] = $this->documentScenario();
        $otherDocument = $this->originalDocument($otherContext, $otherContract);
        $otherVersion = app(ContractDocumentService::class)->upload($otherContext, $otherContract->id, $otherDocument->id,
            ['contract_version' => $otherContract->version, 'purpose' => 'draft', 'file' => $this->documentPdf('FOREIGN')])['version'];
        $this->reject(fn () => DB::table('contract_document_versions')->insert([...$attributes, 'id' => (string) Str::uuid(),
            'number' => 2, 'artifact_id' => $otherVersion->artifact_id]));
        $this->assertDatabaseCount('contract_document_versions', 2);
    }

    public function test_sql_rejects_nonconsecutive_numbers_and_unready_artifacts(): void
    {
        Storage::fake('local');
        [, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        $version = app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
            ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf()])['version'];
        $attributes = $version->getRawOriginal();
        unset($attributes['id']);
        $this->reject(fn () => DB::table('contract_document_versions')->insert([...$attributes, 'id' => (string) Str::uuid(), 'number' => 3]));
        DB::table('tenant_artifacts')->where('id', $version->artifact_id)->update(['ready' => false]);
        $this->reject(fn () => DB::table('contract_document_versions')->insert([...$attributes, 'id' => (string) Str::uuid(), 'number' => 2]));
        $this->assertDatabaseCount('contract_document_versions', 1);
    }

    public function test_sql_rejects_avenant_without_signed_original_and_original_in_archived_contract(): void
    {
        [, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        $attributes = $document->getRawOriginal();
        unset($attributes['versions_count']);
        $this->reject(fn () => DB::table('contract_documents')->insert([...$attributes, 'id' => (string) Str::uuid(), 'kind' => 'amendment',
            'reference' => 'avenant', 'original_document_id' => $document->id]));
        app(ContractService::class)->transition($context, $contract->id, ['version' => $contract->version, 'status' => 'archived']);
        $this->reject(fn () => DB::table('contract_documents')->insert([...$attributes, 'id' => (string) Str::uuid(), 'reference' => 'other']));
    }

    public function test_export_keeps_only_owned_documents_ciphertext_and_real_hashes(): void
    {
        Storage::fake('local');
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        $version = app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
            ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf()])['version'];
        ContractDocument::factory()->create();
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($org->id, 1000));
        $docs = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'contract_documents'));
        $versions = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'contract_document_versions'));
        $this->assertCount(1, $docs);
        $this->assertCount(1, $versions);
        $this->assertNotSame('Document confidentiel', $docs[0]['data']['title']);
        $this->assertSame($version->sha256, $versions[0]['data']['sha256']);
        $this->assertNotSame('contrat.pdf', $versions[0]['data']['filename']);
    }

    public function test_empty_migration_round_trip_preserves_existing_contract_without_backfill(): void
    {
        $this->withoutRetentionGuards(function (): void {
            $contract = ContractRecord::factory()->create();
            $before = $contract->refresh()->getRawOriginal();
            $migration = require database_path('migrations/2026_10_14_193828_create_contract_documents_and_immutable_versions.php');
            $scans = require database_path('migrations/2026_10_14_204339_create_contract_document_scans.php');
            $governance = require database_path('migrations/2026_10_14_231650_add_contract_financial_governance.php');
            $reminders = require database_path('migrations/2026_10_15_184218_create_contract_reminders.php');
            $reminders->down();
            $governance->down();
            $scans->down();
            $migration->down();
            $migration->up();
            $scans->up();
            $governance->up();
            $reminders->up();
            $this->assertSame($before, $contract->refresh()->getRawOriginal());
            $this->assertDatabaseCount('contract_documents', 0);
            $this->assertSame([], app(TenantSchemaInspector::class)->violations());
            ContractDocument::factory()->create(['contract_id' => $contract->id]);
            $this->reject(fn () => DB::table('contract_documents')->delete());
        });
    }

    public function test_rollback_refuses_data_before_dropping_any_document_table(): void
    {
        ContractDocument::factory()->create();
        $migration = require database_path('migrations/2026_10_14_193828_create_contract_documents_and_immutable_versions.php');
        try {
            $migration->down();
            $this->fail('History must be preserved.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('cannot discard document history', $error->getMessage());
            $this->assertDatabaseCount('contract_documents', 1);
            $this->assertDatabaseCount('contract_document_versions', 0);
            $this->assertDatabaseCount('contract_document_signatures', 0);
        }
    }

    /** @param Closure(): mixed $write */
    private function reject(Closure $write, string $pgCode = '23514'): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database must reject this write.');
        } catch (QueryException $error) {
            $this->assertSame(DB::getDriverName() === 'pgsql' ? $pgCode : '23000', (string) $error->getCode());
        }
    }
}
