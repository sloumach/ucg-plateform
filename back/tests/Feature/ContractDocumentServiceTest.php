<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Application\Services\ContractDocumentService;
use App\Modules\Contracts\Application\Services\ContractService;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentRepository;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Fixtures\ProvidesContractDocuments;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ContractDocumentServiceTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesContractDocuments, ProvidesPeopleAccess;

    public function test_audit_failure_cleans_only_the_new_file_and_preserves_previous_version(): void
    {
        Storage::fake('local');
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        $service = app(ContractDocumentService::class);
        $first = $service->upload($context, $contract->id, $document->id, ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf()])['version'];
        $contract->refresh();
        $before = $contract->version;
        $used = DB::table('tenant_storage_usage')->where('organization_id', $org->id)->value('bytes');
        $audit = $this->mock(TenantAuditJournal::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        try {
            app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
                ['contract_version' => $before, 'purpose' => 'draft', 'file' => $this->documentPdf('Second')]);
            $this->fail('An audit failure must not leave a version.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('contract_document_versions', 1);
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertSame($before, $contract->refresh()->version);
        $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $org->id, 'bytes' => $used]);
        Storage::disk('local')->assertExists('tenants/'.$org->id.'/artifacts/'.$first->artifact_id);
        $this->assertCount(1, Storage::disk('local')->allFiles('tenants/'.$org->id));
    }

    public function test_mid_upload_conflict_rechecks_version_after_storage_and_cleans_new_reservation(): void
    {
        Storage::fake('local');
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        $old = $contract->version;
        $next = (string) Str::uuid();
        // The ledger insert occurs between the first object check and the metadata transaction.
        DB::listen(function ($query) use ($contract, $next): void {
            if (str_starts_with(strtolower($query->sql), 'insert into "tenant_artifacts"')) {
                DB::table('contracts')->where('id', $contract->id)->update(['version' => $next]);
            }
        });
        try {
            app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
                ['contract_version' => $old, 'purpose' => 'draft', 'file' => $this->documentPdf()]);
            $this->fail('The captured contract version is stale.');
        } catch (DomainConflictException $error) {
            $this->assertSame('CONTRACT_VERSION_STALE', $error->errorCode());
        }
        $this->assertSame($next, $contract->refresh()->version);
        $this->assertDatabaseCount('contract_document_versions', 0);
        $this->assertDatabaseCount('tenant_artifacts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('tenants/'.$org->id));
    }

    public function test_storage_quota_prevents_a_version_without_changing_the_contract(): void
    {
        Storage::fake('local');
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        config(['tenancy.limit_overrides.'.$org->id.'.storage_bytes' => 1]);
        $before = $contract->version;
        try {
            app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
                ['contract_version' => $before, 'purpose' => 'draft', 'file' => $this->documentPdf()]);
            $this->fail('The encrypted bytes must respect the tenant quota.');
        } catch (TenantLimitExceededException) {
            $this->assertDatabaseCount('contract_document_versions', 0);
            $this->assertDatabaseCount('tenant_artifacts', 0);
            $this->assertSame($before, $contract->refresh()->version);
            $this->assertSame([], Storage::disk('local')->allFiles('tenants/'.$org->id));
        }
    }

    public function test_a_new_preparation_changes_the_preparer_and_blocks_self_approval(): void
    {
        Storage::fake('local');
        [$org, , $contract, $first] = $this->documentScenario();
        $document = $this->originalDocument($first, $contract);
        app(ContractService::class)->transition($first, $contract->id, ['version' => $contract->version, 'status' => 'in_review']);
        $contract->refresh();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['administrative.read', 'administrative.write', 'administrative.validate']);
        $context = $this->personContext($org, $user);
        app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
            ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf()]);
        $this->assertSame($user->id, $contract->refresh()->prepared_by);
        try {
            app(ContractService::class)->transition($context, $contract->id, ['version' => $contract->version, 'status' => 'approved']);
            $this->fail('A prepared document cannot be self approved.');
        } catch (DomainConflictException $error) {
            $this->assertSame('CONTRACT_SELF_APPROVAL_FORBIDDEN', $error->errorCode());
        }
    }

    public function test_preparation_and_signed_copy_follow_parent_status_and_archive_is_terminal(): void
    {
        Storage::fake('local');
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        try {
            app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
                ['contract_version' => $contract->version, 'purpose' => 'signed_copy', 'file' => $this->documentPdf()]);
            $this->fail('A draft contract cannot receive a signed copy.');
        } catch (DomainConflictException $error) {
            $this->assertSame('CONTRACT_DOCUMENT_SIGNATURE_REQUIRED', $error->errorCode());
        }
        app(ContractService::class)->transition($context, $contract->id, ['version' => $contract->version, 'status' => 'archived']);
        $contract->refresh();
        try {
            app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
                ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf()]);
            $this->fail('An archived contract is immutable.');
        } catch (DomainConflictException $error) {
            $this->assertSame('CONTRACT_ARCHIVED', $error->errorCode());
        }
        $this->assertDatabaseCount('tenant_artifacts', 0);
    }

    public function test_signed_original_date_must_match_declaration_and_prepared_file_cannot_be_marked_signed(): void
    {
        Storage::fake('local');
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        $draft = app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
            ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf()])['version'];
        $contract->refresh();
        [, $validator] = $this->declareContractSignature($org, $contract, $context);
        try {
            app(ContractDocumentService::class)->sign($validator, $contract->id, $document->id,
                ['contract_version' => $contract->version, 'document_version_id' => $draft->id, 'signed_on' => now()->utc()->toDateString(), 'confirm_signed_copy' => true]);
            $this->fail('The prepared PDF is not a received signed copy.');
        } catch (DomainConflictException $error) {
            $this->assertSame('CONTRACT_DOCUMENT_SIGNED_COPY_REQUIRED', $error->errorCode());
        }
        $signed = app(ContractDocumentService::class)->upload($validator, $contract->id, $document->id,
            ['contract_version' => $contract->version, 'purpose' => 'signed_copy', 'file' => $this->documentPdf('SIGNATURE')])['version'];
        $contract->refresh();
        try {
            app(ContractDocumentService::class)->sign($validator, $contract->id, $document->id,
                ['contract_version' => $contract->version, 'document_version_id' => $signed->id, 'signed_on' => now()->utc()->subDay()->toDateString(), 'confirm_signed_copy' => true]);
            $this->fail('The original signature date must agree.');
        } catch (ValidationException $error) {
            $this->assertSame(__('contract_documents.signature_date'), $error->errors()['signed_on'][0]);
        }
        $this->assertDatabaseCount('contract_document_signatures', 0);
    }

    public function test_revoked_writer_context_is_refused_before_metadata_or_storage(): void
    {
        [$org, $user, $contract, $context] = $this->documentScenario();
        OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        $this->expectException(AuthorizationException::class);
        app(ContractDocumentService::class)->create($context, $contract->id,
            ['contract_version' => $contract->version, 'reference' => 'original', 'kind' => 'original', 'title' => 'X']);
    }

    public function test_document_list_query_count_is_constant_and_versions_are_separately_paginated(): void
    {
        Storage::fake('local');
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        [, $validator] = $this->declareContractSignature($org, $contract, $context);
        $service = app(ContractDocumentService::class);
        $version = $service->upload($validator, $contract->id, $document->id, ['contract_version' => $contract->version,
            'purpose' => 'signed_copy', 'file' => $this->documentPdf()])['version'];
        $contract->refresh();
        $service->sign($validator, $contract->id, $document->id, ['contract_version' => $contract->version,
            'document_version_id' => $version->id, 'signed_on' => now()->utc()->toDateString(), 'confirm_signed_copy' => true]);
        $contract->refresh();
        $repo = app(ContractDocumentRepository::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $repo->paginate($org->id, $contract->id, []);
        $one = count(DB::getQueryLog());
        for ($i = 1; $i <= 100; $i++) {
            $repo->create(['organization_id' => $org->id, 'contract_id' => $contract->id, 'reference' => 'avenant-'.$i,
                'kind' => 'amendment', 'title' => 'Avenant', 'original_document_id' => $document->id,
                'effective_on' => null, 'created_by' => $validator->actorUserId, 'created_at' => now()]);
        }
        DB::flushQueryLog();
        $page = $repo->paginate($org->id, $contract->id, []);
        $this->assertSame($one, count(DB::getQueryLog()));
        $this->assertCount(10, $page->items());
        $this->assertSame(101, $page->total());
        $this->assertFalse($repo->find($org->id, $contract->id, $document->id)->relationLoaded('versions'));
        DB::disableQueryLog();
        try {
            $service->create($validator, $contract->id, ['contract_version' => $contract->version, 'reference' => 'avenant-101',
                'kind' => 'amendment', 'title' => 'Limite', 'original_document_id' => $document->id]);
            $this->fail('The document budget includes the original and retained amendments.');
        } catch (DomainConflictException $error) {
            $this->assertSame('CONTRACT_DOCUMENT_LIMIT', $error->errorCode());
        }
    }

    public function test_one_hundred_versions_are_retained_and_the_next_upload_is_refused_before_storage(): void
    {
        Storage::fake('local');
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        $service = app(ContractDocumentService::class);
        for ($number = 1; $number <= 100; $number++) {
            $service->upload($context, $contract->id, $document->id, ['contract_version' => $contract->version,
                'purpose' => 'draft', 'file' => $this->documentPdf('Revision '.$number)]);
            $contract->refresh();
        }
        try {
            $service->upload($context, $contract->id, $document->id, ['contract_version' => $contract->version,
                'purpose' => 'draft', 'file' => $this->documentPdf('Overflow')]);
            $this->fail('The retained version budget must be enforced.');
        } catch (DomainConflictException $error) {
            $this->assertSame('CONTRACT_DOCUMENT_VERSION_LIMIT', $error->errorCode());
        }
        $this->assertDatabaseCount('contract_document_versions', 100);
        $this->assertDatabaseCount('tenant_artifacts', 100);
        $this->assertCount(100, Storage::disk('local')->allFiles('tenants/'.$org->id));
        $page = $service->versions($context, $contract->id, $document->id, ['per_page' => 1]);
        $this->assertSame(100, $page->total());
        $this->assertSame(100, $page->items()[0]->number);
    }

    public function test_signature_audit_failure_preserves_file_and_rolls_back_binding_and_parent_version(): void
    {
        Storage::fake('local');
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        [, $validator] = $this->declareContractSignature($org, $contract, $context);
        $version = app(ContractDocumentService::class)->upload($validator, $contract->id, $document->id,
            ['contract_version' => $contract->version, 'purpose' => 'signed_copy', 'file' => $this->documentPdf()])['version'];
        $contract->refresh();
        $before = $contract->version;
        $audit = $this->mock(TenantAuditJournal::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Binding audit unavailable'));
        try {
            app(ContractDocumentService::class)->sign($validator, $contract->id, $document->id,
                ['contract_version' => $before, 'document_version_id' => $version->id, 'signed_on' => now()->utc()->toDateString(), 'confirm_signed_copy' => true]);
            $this->fail('A binding requires its atomic audit.');
        } catch (RuntimeException $error) {
            $this->assertSame('Binding audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('contract_document_signatures', 0);
        $this->assertDatabaseCount('contract_document_versions', 1);
        $this->assertSame($before, $contract->refresh()->version);
        Storage::disk('local')->assertExists('tenants/'.$org->id.'/artifacts/'.$version->artifact_id);
    }
}
