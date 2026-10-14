<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\ProvidesDocumentSecurity;
use Tests\TestCase;

class ContractDocumentScanSchemaTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesDocumentSecurity;

    /** @return array<string, array{array<string, mixed>}> */
    public static function illegalUpdates(): array
    {
        return ['identity' => [['id' => '11111111-1111-4111-8111-111111111111']],
            'hash' => [['sha256' => 'f'.str_repeat('0', 63)]], 'policy' => [['policy' => 'unchecked']],
            'attempt' => [['attempt' => 99]], 'actor' => [['requested_by' => 99999]],
            'approve directly' => [['status' => 'clean', 'engine' => 'ClamAV forged', 'finished_at' => '2026-10-14 00:00:00']],
            'unknown result' => [['status' => 'failed', 'reason' => 'arbitrary', 'finished_at' => '2026-10-14 00:00:00']]];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('illegalUpdates')]
    public function test_sql_rejects_identity_rewrites_and_uncontrolled_verdicts(array $changes): void
    {
        [, , , , , , $scan] = $this->securityScenario();
        $this->reject(fn () => DB::table('contract_document_scans')->where('id', $scan->id)->update($changes));
        $this->assertSame('queued', $scan->refresh()->status);
    }

    public function test_terminal_verdict_cannot_change_or_be_deleted_and_invalidation_is_once_only(): void
    {
        [, , , $context, , , $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $this->reject(fn () => DB::table('contract_document_scans')->where('id', $scan->id)->update(['status' => 'failed', 'reason' => 'integrity']));
        $this->reject(fn () => DB::table('contract_document_scans')->where('id', $scan->id)->delete());
        DB::table('contract_document_scans')->where('id', $scan->id)->update(['invalidated_at' => now()]);
        $this->reject(fn () => DB::table('contract_document_scans')->where('id', $scan->id)->update(['invalidated_at' => null]));
        $this->assertSame('clean', $scan->refresh()->status);
        $this->assertNotNull($scan->invalidated_at);
    }

    public function test_insert_requires_queued_consecutive_attempt_and_matching_owned_hash(): void
    {
        [, , , , , , $scan] = $this->securityScenario();
        $raw = $scan->getRawOriginal();
        $this->reject(fn () => DB::table('contract_document_scans')->insert([...$raw, 'id' => (string) Str::uuid(), 'attempt' => 3]));
        $this->reject(fn () => DB::table('contract_document_scans')->insert([...$raw, 'id' => (string) Str::uuid(), 'attempt' => 2, 'sha256' => str_repeat('0', 64)]));
        $this->reject(fn () => DB::table('contract_document_scans')->insert([...$raw, 'id' => (string) Str::uuid(), 'attempt' => 2, 'document_version_id' => (string) Str::uuid()]));
        $this->assertDatabaseCount('contract_document_scans', 1);
    }

    public function test_catalog_owns_scan_rows_without_any_plain_file_contents(): void
    {
        [$org] = $this->securityScenario();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $repository = app(PortabilityRepository::class);
        $repository->assertCatalog();
        $rows = array_values(array_filter(iterator_to_array($repository->rows($org->id, 1000)), fn ($row) => $row['table'] === 'contract_document_scans'));
        $this->assertCount(1, $rows);
        $this->assertStringNotContainsString('PRIVATE DOCUMENT', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function test_rollback_refuses_history_and_empty_roundtrip_restores_guards(): void
    {
        $migration = require database_path('migrations/2026_10_14_204339_create_contract_document_scans.php');
        $this->assertDatabaseCount('contract_document_scans', 0);
        $migration->down();
        $migration->up();
        [, , , , , , $scan] = $this->securityScenario();
        try {
            $migration->down();
            $this->fail('Scan history must survive rollback.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('cannot discard document scans', $error->getMessage());
            $this->assertDatabaseCount('contract_document_scans', 1);
        }
        $this->reject(fn () => DB::table('contract_document_scans')->where('id', $scan->id)->delete());
    }

    /** @param Closure(): mixed $write */
    private function reject(Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database must reject this write.');
        } catch (QueryException $error) {
            $this->assertSame(DB::getDriverName() === 'pgsql' ? '23514' : '23000', (string) $error->getCode());
        }
    }
}
