<?php

namespace Tests\Feature;

use App\Modules\Contracts\Application\Services\ContractDocumentScanService;
use App\Modules\Contracts\Application\Services\ContractDocumentService;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_version_list_loads_latest_verdicts_without_per_version_queries(): void
    {
        [$org, $user, $contract, $context, $document] = $this->securityScenario();
        $this->actingAsWithMfa($user);
        $path = $this->documentPath($org, $contract, $document->id).'/versions';
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonPath('data.0.scan_status', 'queued');
        $one = count(DB::getQueryLog());
        for ($number = 0; $number < 10; $number++) {
            $contract->refresh();
            app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
                ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf('Revision '.$number)]);
        }
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 11)->assertJsonPath('data.0.scan_status', 'queued');
        $this->assertSame($one, count(DB::getQueryLog()));
        DB::disableQueryLog();
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
}
