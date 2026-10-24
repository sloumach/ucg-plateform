<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Application\Contracts\ContractDocumentScanQueue;
use App\Modules\Contracts\Application\Services\ContractDocumentScanProcessor;
use App\Modules\Contracts\Application\Services\ContractDocumentScanService;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentScanner;
use App\Modules\Contracts\Domain\ScanVerdict;
use App\Modules\Contracts\Infrastructure\Queue\ScanContractDocument;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Support\Queue\JobContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Fixtures\ProvidesDocumentSecurity;
use Tests\TestCase;

class ContractDocumentScanTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesDocumentSecurity;

    public function test_upload_queues_a_scoped_analysis_and_duplicate_requests_dispatch_once(): void
    {
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $again = app(ContractDocumentScanService::class)->request($context, $contract->id, $document->id, $version->id, ['expected_scan_id' => null]);
        $this->assertSame($scan->id, $again->id);
        $this->assertSame('queued', $again->status);
        Queue::assertPushed(ScanContractDocument::class, fn ($job) => $job->scanId === $scan->id && $job->jobContext()->tenantId === $org->id && $job->jobContext()->actorUserId === $user->id);
        Queue::assertPushed(ScanContractDocument::class, 1);
        $this->assertDatabaseCount('contract_document_scans', 1);
    }

    public function test_job_runs_through_tenant_middleware_and_duplicate_delivery_keeps_original_verdict(): void
    {
        [$org, $user, , , , , $scan] = $this->securityScenario();
        $this->mock(ContractDocumentScanner::class)->shouldReceive('scan')->once()->andReturn(new ScanVerdict('clean', engine: 'ClamAV test/123/fresh'));
        Queue::fake()->except(ScanContractDocument::class);
        $job = new ScanContractDocument(new JobContext((string) Str::uuid(), $org->id, $user->id), $scan->id);
        Bus::dispatchSync($job);
        $before = $scan->refresh()->getRawOriginal();
        Bus::dispatchSync($job);
        $this->assertSame('clean', $scan->refresh()->status);
        $this->assertSame($before, $scan->getRawOriginal());
        $audit = json_encode(DB::table('organization_audit_events')->get()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE DOCUMENT', $audit);
        $this->assertStringNotContainsString('contrat.pdf', $audit);
    }

    public function test_integrity_failure_never_calls_the_scanner(): void
    {
        [$org, , , $context, , $version, $scan] = $this->securityScenario();
        Storage::disk('local')->put('tenants/'.$org->id.'/artifacts/'.$version->artifact_id, encrypt("%PDF-1.4\nCHANGED\n%%EOF", false));
        $this->mock(ContractDocumentScanner::class)->shouldNotReceive('scan');
        app(ContractDocumentScanProcessor::class)->process($context, $scan->id);
        $this->assertSame('rejected', $scan->refresh()->status);
        $this->assertSame('integrity', $scan->reason);
    }

    public function test_negative_scan_and_failed_dispatch_preserve_file_and_allow_explicit_retry(): void
    {
        [, , $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->mock(ContractDocumentScanner::class)->shouldReceive('scan')->once()->andReturn(new ScanVerdict('rejected', 'malware', 'ClamAV test/123/fresh'));
        app(ContractDocumentScanProcessor::class)->process($context, $scan->id);
        $this->mock(ContractDocumentScanQueue::class)->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
        $retry = app(ContractDocumentScanService::class)->request($context, $contract->id, $document->id, $version->id, ['expected_scan_id' => $scan->id]);
        $this->assertSame(2, $retry->attempt);
        $this->assertSame('failed', $retry->status);
        $this->assertSame('dispatch_failed', $retry->reason);
        $this->assertDatabaseCount('contract_document_versions', 1);
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertSame('rejected', $scan->refresh()->status);
    }

    public function test_expired_run_is_failed_and_superseded_without_rewriting_a_newer_verdict(): void
    {
        $this->freezeTime();
        [, , $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->travel(301)->seconds();
        $retry = app(ContractDocumentScanService::class)->request($context, $contract->id, $document->id, $version->id, ['expected_scan_id' => $scan->id]);
        $this->assertSame('timed_out', $scan->refresh()->reason);
        $this->approveScan($context, $retry);
        app(ContractDocumentScanProcessor::class)->process($context, $scan->id);
        $this->assertSame('clean', $retry->refresh()->status);
        $this->assertSame('failed', $scan->refresh()->status);
    }

    public function test_stale_expected_verdict_cannot_start_another_analysis(): void
    {
        [, , $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $this->expectException(DomainConflictException::class);
        app(ContractDocumentScanService::class)->request($context, $contract->id, $document->id, $version->id, ['expected_scan_id' => null]);
    }

    public function test_scan_superseded_during_io_cannot_overwrite_the_newer_run(): void
    {
        $this->freezeTime();
        [, , $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->mock(ContractDocumentScanner::class)->shouldReceive('scan')->once()->andReturnUsing(function () use ($contract, $context, $document, $version, $scan) {
            $this->travel(301)->seconds();
            app(ContractDocumentScanService::class)->request($context, $contract->id, $document->id, $version->id, ['expected_scan_id' => $scan->id]);

            return new ScanVerdict('clean', engine: 'ClamAV test/123/fresh');
        });
        app(ContractDocumentScanProcessor::class)->process($context, $scan->id);
        $this->assertSame('timed_out', $scan->refresh()->reason);
        $this->assertDatabaseHas('contract_document_scans', ['document_version_id' => $version->id, 'attempt' => 2, 'status' => 'queued']);
        $this->assertDatabaseCount('contract_document_scans', 2);
    }

    public function test_scan_audit_failure_rolls_back_only_new_analysis_and_keeps_the_document(): void
    {
        [, , $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        try {
            app(ContractDocumentScanService::class)->request($context, $contract->id, $document->id, $version->id, ['expected_scan_id' => $scan->id]);
            $this->fail('Unjournaled scan must not persist.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
            $this->assertSame('clean', $scan->refresh()->status);
            $this->assertDatabaseCount('contract_document_scans', 1);
            $this->assertDatabaseCount('tenant_artifacts', 1);
        }
    }

    public function test_access_revoked_during_scanning_cannot_publish_a_clean_verdict(): void
    {
        [$org, $user, , $context, , , $scan] = $this->securityScenario();
        $this->mock(ContractDocumentScanner::class)->shouldReceive('scan')->once()->andReturnUsing(function () use ($org, $user) {
            OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)->delete();

            return new ScanVerdict('clean', engine: 'ClamAV test/123/fresh');
        });
        try {
            app(ContractDocumentScanProcessor::class)->process($context, $scan->id);
            $this->fail('Revoked access must fail closed.');
        } catch (AuthorizationException) {
            $this->assertSame('processing', $scan->refresh()->status);
        }
        app(ContractDocumentScanProcessor::class)->failed($org->id, $scan->id, $user->id);
        $this->assertSame('worker_failed', $scan->refresh()->reason);
    }

    #[Group('tenant-isolation')]
    public function test_serialized_scan_cannot_use_a_foreign_identifier_even_with_rights_in_both_tenants(): void
    {
        [$org, $user, , , , $version, $scan] = $this->securityScenario();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, ['administrative.read', 'administrative.write']);
        $transport = serialize(new ScanContractDocument(new JobContext((string) Str::uuid(), $other->id, $user->id), $scan->id));
        $before = $scan->getRawOriginal();
        $filePath = 'tenants/'.$org->id.'/artifacts/'.$version->artifact_id;
        $stored = Storage::disk('local')->get($filePath);
        $auditCount = DB::table('organization_audit_events')->count();
        $this->mock(ContractDocumentScanner::class)->shouldNotReceive('scan');
        $this->mock(TenantArtifacts::class)->shouldNotReceive('read');
        Queue::assertPushed(ScanContractDocument::class, 1);

        Queue::fake()->except([ScanContractDocument::class]);
        try {
            Bus::dispatchSync(unserialize($transport));
            $this->fail('A tenant transport must not authorize a foreign scan identifier.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $scan->refresh()->getRawOriginal());
        }

        $this->assertSame($stored, Storage::disk('local')->get($filePath));
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('contract_document_scans', 1);
        $this->assertDatabaseCount('contract_document_versions', 1);
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }
}
