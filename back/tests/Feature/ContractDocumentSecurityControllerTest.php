<?php

namespace Tests\Feature;

use App\Modules\Contracts\Application\Services\ContractDocumentScanProcessor;
use App\Modules\Contracts\Application\Services\ContractDocumentScanService;
use App\Modules\Contracts\Application\Services\ContractDocumentService;
use App\Modules\Contracts\Application\Services\ContractFinancialService;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentScanner;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractDocumentScan;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Domain\ScanVerdict;
use App\Modules\Contracts\Infrastructure\Queue\ScanContractDocument;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesDocumentSecurity;
use Tests\TestCase;

class ContractDocumentSecurityControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesDocumentSecurity;

    public function test_only_clean_recent_documents_download_with_private_headers_and_exact_bytes(): void
    {
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->actingAsWithMfa($user);
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertConflict()->assertJsonPath('code', 'CONTRACT_DOCUMENT_NOT_READY');
        $this->approveScan($context, $scan);
        $url = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->assertJsonMissingPath('data.artifact_id')->json('data.url');
        $this->assertIsString($url);
        $this->assertStringStartsWith($path.'/download?', $url);
        $response = $this->get($url, ['Accept' => 'application/pdf'])->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertIsString($response->headers->get('Cache-Control'));
        $this->assertIsString($response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame($this->documentPdf()->getContent(), $response->getContent());
        $this->getJson($path.'/scans?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.organization_id');
    }

    /** @return array<string,array{string,string}> */
    public static function blockingRescans(): array
    {
        return ['malware' => ['rejected', 'malware'], 'outage' => ['failed', 'scanner_unavailable'],
            'ambiguous reply' => ['failed', 'scanner_response'], 'stale signatures' => ['failed', 'scanner_database_stale']];
    }

    #[DataProvider('blockingRescans')]
    public function test_negative_rescan_revokes_an_issued_link_and_keeps_encrypted_quarantine(string $status, string $reason): void
    {
        $this->freezeTime();
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->mock(ContractDocumentScanner::class)->shouldReceive('scan')->twice()
            ->andReturn(new ScanVerdict('clean', engine: 'ClamAV test/123/fresh'), new ScanVerdict($status, $reason));
        app(ContractDocumentScanProcessor::class)->process($context, $scan->id);
        $this->actingAsWithMfa($user);
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $url = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        $this->get($url)->assertOk();
        $storagePath = 'tenants/'.$org->id.'/artifacts/'.$version->artifact_id;
        $ciphertext = Storage::disk('local')->get($storagePath);
        $usage = DB::table('tenant_storage_usage')->where('organization_id', $org->id)->value('bytes');
        $retry = app(ContractDocumentScanService::class)->request($context, $contract->id, $document->id, $version->id, ['expected_scan_id' => $scan->id]);
        app(ContractDocumentScanProcessor::class)->process($context, $retry->id);
        $this->assertSame($status, $retry->refresh()->status);
        $this->assertSame($reason, $retry->reason);
        $this->getJson($url)->assertConflict()->assertJsonPath('code', 'CONTRACT_DOCUMENT_NOT_READY');
        $this->postJson($path.'/download-link', ['expected_scan_id' => $retry->id])->assertConflict()->assertJsonPath('code', 'CONTRACT_DOCUMENT_NOT_READY');
        $this->assertSame($ciphertext, Storage::disk('local')->get($storagePath));
        $this->assertSame($usage, DB::table('tenant_storage_usage')->where('organization_id', $org->id)->value('bytes'));
        $this->assertDatabaseCount('contract_document_versions', 1);
        $this->assertDatabaseCount('contract_document_scans', 2);
    }

    public function test_reader_can_download_but_cannot_enqueue_analysis_and_link_is_bound_to_actor(): void
    {
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $reader = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $reader, ['administrative.read']);
        $this->actingAsWithMfa($reader);
        $this->postJson($path.'/scans', ['expected_scan_id' => $scan->id])->assertForbidden();
        $url = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        $this->get($url)->assertOk();
        $this->actingAsWithMfa($user);
        $this->getJson($url)->assertConflict()->assertJsonPath('code', 'CONTRACT_DOCUMENT_LINK_INVALID');
        $this->getJson(str_replace('actor='.$reader->id, 'actor='.$user->id, $url))->assertConflict();
    }

    public function test_financial_classification_rechecks_existing_signed_links_and_requires_financial_read(): void
    {
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $this->actingAsWithMfa($user);
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $url = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        $finance = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $finance, ['administrative.read', 'administrative.write', 'financial.read', 'financial.write']);
        $contract->refresh();
        app(ContractFinancialService::class)->save($this->personContext($org, $finance), $contract->id,
            ['contract_version' => $contract->version, 'expected_version' => null, 'terms' => ['currency' => 'EUR', 'remuneration' => null,
                'remuneration_period' => null, 'bonus' => '100.00', 'prize_share_percent' => null, 'payment_terms' => null]]);
        $this->getJson($url)->assertForbidden();
        $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertForbidden();
        $this->actingAsWithMfa($finance);
        $fresh = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        $this->get($fresh)->assertOk();
    }

    public function test_link_expires_at_boundary_and_cannot_be_reused_after_a_new_scan(): void
    {
        $this->freezeTime();
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $this->actingAsWithMfa($user);
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $url = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        $this->travel(300)->seconds();
        $this->getJson($url)->assertConflict()->assertJsonPath('code', 'CONTRACT_DOCUMENT_LINK_INVALID');
        $fresh = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        app(ContractDocumentScanService::class)->request($context, $contract->id, $document->id, $version->id, ['expected_scan_id' => $scan->id]);
        $this->getJson($fresh)->assertConflict()->assertJsonPath('code', 'CONTRACT_DOCUMENT_NOT_READY');
    }

    public function test_stale_clean_verdict_and_modified_storage_withdraw_download_access(): void
    {
        $this->freezeTime();
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $this->actingAsWithMfa($user);
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $url = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        Storage::disk('local')->put('tenants/'.$org->id.'/artifacts/'.$version->artifact_id, encrypt("%PDF-1.4\nALTERED\n%%EOF", false));
        $this->getJson($url)->assertConflict()->assertJsonPath('code', 'CONTRACT_DOCUMENT_INTEGRITY_FAILURE');
        $this->assertNotNull($scan->refresh()->invalidated_at);
        $this->assertSame('clean', $scan->status);
        $this->getJson($url)->assertConflict()->assertJsonPath('code', 'CONTRACT_DOCUMENT_NOT_READY');
        $this->getJson($this->documentPath($org, $contract, $document->id).'/versions')->assertOk()
            ->assertJsonPath('data.0.scan_status', 'rejected')->assertJsonPath('data.0.download_available', false);
    }

    public function test_expired_analysis_needs_rescan_and_no_public_file_path_is_exposed(): void
    {
        $this->freezeTime();
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $this->travel(86400)->seconds();
        $this->actingAsWithMfa($user);
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertConflict();
        $this->getJson($this->documentPath($org, $contract, $document->id).'/versions')->assertOk()
            ->assertJsonPath('data.0.scan_status', 'stale')->assertJsonMissingPath('data.0.artifact_id')->assertJsonMissingPath('data.0.url');
    }

    /** @return array<string, array{string}> */
    public static function tenantCacheStores(): array
    {
        return ['memory cache' => ['array'], 'CI database cache' => ['database']];
    }

    #[DataProvider('tenantCacheStores')]
    public function test_version_list_loads_latest_verdicts_without_per_version_queries(string $cacheStore): void
    {
        $this->freezeTime();
        config(['tenancy.cache_store' => $cacheStore]);
        [$org, $user, $contract, $context, $document] = $this->securityScenario();
        $this->actingAsWithMfa($user);
        $path = $this->documentPath($org, $contract, $document->id).'/versions';
        // Count document reads only: the HTTP limiter inserts cache entries on its first request.
        $documentQueries = static fn (): array => array_values(array_filter(DB::getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], '"contract_document_versions"')
                || str_contains($query['query'], '"contract_document_scans"')));
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->getJson($path)->assertOk()->assertJsonPath('data.0.scan_status', 'queued');
            $one = count($documentQueries());
            for ($number = 0; $number < 10; $number++) {
                $contract->refresh();
                app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
                    ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf('Revision '.$number)]);
            }
            DB::flushQueryLog();
            $this->getJson($path)->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 11)->assertJsonPath('data.0.scan_status', 'queued');
            $queries = $documentQueries();
            $this->assertSame($one, count($queries));
            $scanQueries = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "contract_document_scans"'));
            $this->assertCount(1, $scanQueries);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertDatabaseCount('contract_document_scans', 11);
    }

    public function test_permissions_revoked_during_storage_read_block_the_download_response(): void
    {
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $this->actingAsWithMfa($user);
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $url = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        $artifacts = app(TenantArtifacts::class);
        $this->mock(TenantArtifacts::class)->shouldReceive('read')->once()->andReturnUsing(function ($tenant, $id) use ($artifacts, $org, $user) {
            $bytes = $artifacts->read($tenant, $id);
            OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)->delete();

            return $bytes;
        });
        $this->getJson($url)->assertForbidden()->assertDontSee('PRIVATE DOCUMENT');
    }

    public function test_modified_link_and_unsigned_download_never_serve_bytes(): void
    {
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $this->actingAsWithMfa($user);
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $url = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        $this->getJson($path.'/download')->assertConflict();
        $this->getJson($url.'&extra=1')->assertConflict();
        $this->getJson(str_replace($version->id, (string) Str::uuid(), $url))->assertConflict();
        $this->getJson(str_replace($org->id, (string) Str::uuid(), $url))->assertNotFound();
    }

    public function test_latest_scan_history_is_paginated_and_foreign_history_is_hidden(): void
    {
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        app(ContractDocumentScanService::class)->request($context, $contract->id, $document->id, $version->id, ['expected_scan_id' => $scan->id]);
        $this->actingAsWithMfa($user);
        $this->getJson($this->documentPath($org, $contract, $document->id).'/versions/'.$version->id.'/scans?per_page=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $scan->id);
    }

    #[Group('tenant-isolation')]
    public function test_guests_unprivileged_users_foreign_versions_and_unknown_input_are_denied(): void
    {
        [$org, $user, $contract, , $document, $version, $scan] = $this->securityScenario();
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $this->getJson($path.'/scans')->assertUnauthorized();
        $this->actingAsWithMfa($user);
        $this->postJson($path.'/scans', ['expected_scan_id' => $scan->id, 'status' => 'clean'])->assertUnprocessable()->assertJsonValidationErrors('status');
        [$other, , $foreignContract, , $foreignDocument, $foreignVersion] = $this->securityScenario();
        $this->grantPeopleAccess($other, $user, ['administrative.read', 'administrative.write']);
        $this->postJson($this->documentPath($org, $contract, $document->id).'/versions/'.$foreignVersion->id.'/scans', ['expected_scan_id' => null])->assertNotFound();
        $this->postJson($this->documentPath($org, $foreignContract, $foreignDocument->id).'/versions/'.$foreignVersion->id.'/download-link', ['expected_scan_id' => $scan->id])->assertNotFound();
        $staff = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $staff, ['sports.read', 'financial.read']);
        $this->actingAsWithMfa($staff);
        $this->getJson($path.'/scans')->assertForbidden();
    }

    #[Group('tenant-isolation')]
    public function test_serialized_http_scan_and_replay_keep_the_original_tenant_after_context_switch(): void
    {
        $this->freezeTime();
        [$org, $user, $contract, $document, $version, $scan, $transport] = $this->queuedHttpScan();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, ['administrative.read', 'administrative.write']);
        $this->selectOrganization($org);
        $this->selectOrganization($other);
        $filePath = 'tenants/'.$org->id.'/artifacts/'.$version->artifact_id;
        $stored = Storage::disk('local')->get($filePath);
        $contractVersion = $contract->refresh()->version;
        $versionBefore = $version->getRawOriginal();
        $this->mock(ContractDocumentScanner::class)->shouldReceive('scan')->once()
            ->with($this->documentPdf()->getContent())->andReturn(new ScanVerdict('clean', engine: 'ClamAV test/123/fresh'));

        Queue::fake()->except([ScanContractDocument::class]);
        Bus::dispatchSync(unserialize($transport));
        $verdict = $scan->refresh()->getRawOriginal();
        $auditCount = DB::table('organization_audit_events')->count();
        Bus::dispatchSync(unserialize($transport));

        $this->assertSame('clean', $scan->refresh()->status);
        $this->assertSame($verdict, $scan->getRawOriginal());
        $this->assertSame($versionBefore, $version->refresh()->getRawOriginal());
        $this->assertSame($contractVersion, $contract->refresh()->version);
        $this->assertSame($stored, Storage::disk('local')->get($filePath));
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('contract_document_scans', 1);
        $this->assertDatabaseCount('contract_document_versions', 1);
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertSame(0, DB::table('contract_document_scans')->where('organization_id', $other->id)->count());
        $this->assertFalse(app(TenantExecutionScope::class)->active());
        $this->assertSame($other->id, session()->get(ActiveOrganizationSession::KEY.'.organization_id'));
        $this->getJson('/api/v1/tenants/'.$other->id.'/context')->assertOk();
        $this->selectOrganization($org);
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $url = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        $this->assertSame($this->documentPdf()->getContent(), $this->get($url)->assertOk()->getContent());
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership'])]
    #[TestWith(['role'])]
    #[TestWith(['write'])]
    public function test_beta_permissions_cannot_rescue_a_serialized_scan_revoked_before_execution(string $revocation): void
    {
        $this->freezeTime();
        [$org, $user, $contract, , $version, $scan, $transport] = $this->queuedHttpScan();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, ['administrative.read', 'administrative.write']);
        $this->selectOrganization($org);
        $this->selectOrganization($other);
        $filePath = 'tenants/'.$org->id.'/artifacts/'.$version->artifact_id;
        $stored = Storage::disk('local')->get($filePath);
        $versionBefore = $version->getRawOriginal();
        $contractVersion = $contract->refresh()->version;
        $auditCount = DB::table('organization_audit_events')->count();
        $this->revokeDocumentAccess($org, $user, $revocation);
        $this->mock(ContractDocumentScanner::class)->shouldNotReceive('scan');
        $this->mock(TenantArtifacts::class)->shouldNotReceive('read');

        Queue::fake()->except([ScanContractDocument::class]);
        Bus::dispatchSync(unserialize($transport));
        $failed = $scan->refresh()->getRawOriginal();
        Bus::dispatchSync(unserialize($transport));

        $this->assertSame('failed', $scan->refresh()->status);
        $this->assertSame('worker_failed', $scan->reason);
        $this->assertNull($scan->started_at);
        $this->assertNull($scan->engine);
        $this->assertSame($failed, $scan->getRawOriginal());
        $this->assertSame($versionBefore, $version->refresh()->getRawOriginal());
        $this->assertSame($contractVersion, $contract->refresh()->version);
        $this->assertSame($stored, Storage::disk('local')->get($filePath));
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('contract_document_scans', 1);
        $this->assertDatabaseCount('contract_document_versions', 1);
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertSame(0, DB::table('contract_document_scans')->where('organization_id', $other->id)->count());
        $this->assertFalse(app(TenantExecutionScope::class)->active());
        $this->assertSame($other->id, session()->get(ActiveOrganizationSession::KEY.'.organization_id'));
        $this->getJson('/api/v1/tenants/'.$other->id.'/context')->assertOk();
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership', 404])]
    #[TestWith(['role', 403])]
    #[TestWith(['read', 403])]
    public function test_revocation_blocks_an_already_used_signed_link_and_warmed_document_reads(string $revocation, int $status): void
    {
        $this->freezeTime();
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, ['administrative.read', 'administrative.write']);
        $this->approveScan($context, $scan);
        $this->actingAsWithMfa($user);
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $url = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        $this->assertSame($this->documentPdf()->getContent(), $this->get($url)->assertOk()->getContent());
        $this->getJson($path.'/scans')->assertOk();
        $filePath = 'tenants/'.$org->id.'/artifacts/'.$version->artifact_id;
        $stored = Storage::disk('local')->get($filePath);
        $scanBefore = $scan->getRawOriginal();
        $auditCount = DB::table('organization_audit_events')->count();
        $this->revokeDocumentAccess($org, $user, $revocation);
        $this->mock(TenantArtifacts::class)->shouldNotReceive('read');

        $this->getJson($url)->assertStatus($status)->assertDontSee('PRIVATE DOCUMENT')->assertJsonMissingPath('data');
        $this->getJson($this->documentPath($org, $contract, $document->id).'/versions')->assertStatus($status)->assertJsonMissingPath('data');
        $this->getJson($path.'/scans')->assertStatus($status)->assertJsonMissingPath('data');
        $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertStatus($status);
        $this->postJson($path.'/scans', ['expected_scan_id' => $scan->id])->assertStatus($status);
        $this->getJson('/api/v1/tenants/'.$other->id.'/context')->assertOk();

        $this->assertSame($scanBefore, $scan->refresh()->getRawOriginal());
        $this->assertSame($stored, Storage::disk('local')->get($filePath));
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('contract_document_scans', 1);
        $this->assertDatabaseCount('contract_document_versions', 1);
        $this->assertDatabaseCount('tenant_artifacts', 1);
        Queue::assertPushed(ScanContractDocument::class, 1);
    }

    #[Group('tenant-isolation')]
    public function test_removing_document_write_keeps_existing_link_readable_but_blocks_rescanning_and_upload(): void
    {
        $this->freezeTime();
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $this->actingAsWithMfa($user);
        $path = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $url = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        $this->get($url)->assertOk();
        $contractVersion = $contract->refresh()->version;
        $scanBefore = $scan->getRawOriginal();
        $auditCount = DB::table('organization_audit_events')->count();
        $this->revokeDocumentAccess($org, $user, 'write');

        $this->assertSame($this->documentPdf()->getContent(), $this->get($url)->assertOk()->getContent());
        $this->getJson($path.'/scans')->assertOk()->assertJsonPath('data.0.id', $scan->id);
        $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk();
        $this->postJson($path.'/scans', ['expected_scan_id' => $scan->id])->assertForbidden();
        $this->post($this->documentPath($org, $contract, $document->id).'/versions',
            ['contract_version' => $contractVersion, 'purpose' => 'draft', 'file' => $this->documentPdf('NEW CONTENT')], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame($scanBefore, $scan->refresh()->getRawOriginal());
        $this->assertSame($contractVersion, $contract->refresh()->version);
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('contract_document_scans', 1);
        $this->assertDatabaseCount('contract_document_versions', 1);
        $this->assertDatabaseCount('tenant_artifacts', 1);
        Queue::assertPushed(ScanContractDocument::class, 1);
    }

    /** @return array{Organization, User, ContractRecord, ContractDocument, ContractDocumentVersion, ContractDocumentScan, string} */
    private function queuedHttpScan(): array
    {
        Storage::fake('local');
        Queue::fake([ScanContractDocument::class]);
        [$org, $user, $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        $this->actingAsWithMfa($user);
        $response = $this->post($this->documentPath($org, $contract, $document->id).'/versions',
            ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf()], ['Accept' => 'application/json'])->assertCreated();
        $versionId = $response->json('data.id');
        $this->assertIsString($versionId);
        $version = ContractDocumentVersion::findOrFail($versionId);
        $scan = ContractDocumentScan::query()->where('document_version_id', $version->id)->firstOrFail();
        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);
        $job = $queue->pushed(ScanContractDocument::class)->sole();
        $this->assertInstanceOf(ScanContractDocument::class, $job);
        $this->assertSame($org->id, $job->context->tenantId);
        $this->assertSame($user->id, $job->context->actorUserId);
        $this->assertSame($scan->id, $job->scanId);

        return [$org, $user, $contract, $document, $version, $scan, serialize($job)];
    }

    private function selectOrganization(Organization $organization): void
    {
        $revision = $this->postJson('/api/v1/active-organization', ['organization_id' => $organization->id])->assertOk()->json('data.revision');
        $this->withHeader('X-Tenant-Revision', $revision);
        $this->postJson('/api/v1/active-organization/confirmation', ['organization_id' => $organization->id, 'revision' => $revision, 'confirm' => true])->assertOk();
    }

    private function revokeDocumentAccess(Organization $organization, User $user, string $revocation): void
    {
        if ($revocation === 'membership') {
            OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)
                ->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        } else {
            $assignment = OrganizationRoleAssignment::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->firstOrFail();
            if ($revocation === 'role') {
                $assignment->forceFill(['status' => 'revoked'])->save();
            } else {
                $assignment->role->forceFill(['permissions' => $revocation === 'write' ? ['administrative.read'] : ['administrative.write']])->save();
            }
        }
    }
}
