<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Contracts\Application\Services\ContractDocumentService;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Infrastructure\Queue\ScanContractDocument;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantSensitiveAccessJournal;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\OrganizationPortabilityService;
use App\Modules\Tenancy\Domain\Contracts\PortabilityArchive;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Exceptions\InvalidOrganizationAccountException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Infrastructure\Queue\BuildOrganizationExport;
use App\Support\Audit\SensitiveAccessAction;
use App\Support\Audit\SensitiveAccessData;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesDocumentSecurity;
use Tests\TestCase;

class ContractAccessAuditTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesDocumentSecurity;

    /** @return array<string,array{string,string}> */
    public static function reads(): array
    {
        return ['list' => ['', 'contract.listed'], 'detail' => ['/{contract}', 'contract.viewed'],
            'transitions' => ['/{contract}/transitions', 'contract.history_viewed'],
            'finance' => ['/{contract}/financial-terms', 'contract.financial_viewed'],
            'documents' => ['/{contract}/documents', 'contract.documents_viewed'],
            'reminders' => ['/{contract}/reminders', 'contract.reminders_viewed'],
            'reminder history' => ['/{contract}/reminders/history', 'contract.reminder_history_viewed'],
            'inbox' => ['/reminders', 'contract.reminder_inbox_viewed']];
    }

    #[DataProvider('reads')]
    public function test_successful_contract_reads_are_audited_with_only_server_metadata(string $suffix, string $action): void
    {
        $this->freezeTime();
        [$org, $user, $contract] = $this->documentScenario(['administrative.read', 'administrative.write', 'financial.read']);
        $this->actingAsWithMfa($user);
        $requestId = (string) Str::uuid();
        $path = '/api/v1/tenants/'.$org->id.'/contracts'.str_replace('{contract}', $contract->id, $suffix);
        $response = $this->withHeaders(['X-Request-ID' => $requestId, 'X-Access-Purpose' => 'internal_control'])->getJson($path)->assertOk();
        $serverRequestId = $response->headers->get('X-Request-ID');
        $this->assertIsString($serverRequestId);
        $this->assertNotSame($requestId, $serverRequestId);
        $this->assertDatabaseCount('organization_sensitive_access_events', 1);
        $this->assertDatabaseHas('organization_sensitive_access_events', ['organization_id' => $org->id, 'actor_user_id' => $user->id,
            'action' => $action, 'reason_code' => 'internal_control', 'request_id' => $serverRequestId]);
        $encoded = json_encode(DB::table('organization_sensitive_access_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($contract->title, $encoded);
        $this->assertStringNotContainsString($contract->responsible_name, $encoded);
        $this->assertStringNotContainsString('http', $encoded);
    }

    public function test_failed_or_foreign_reads_do_not_create_a_successful_access_event(): void
    {
        [$org, $user, $contract] = $this->documentScenario();
        $path = '/api/v1/tenants/'.$org->id.'/contracts/'.$contract->id;
        $this->getJson($path)->assertUnauthorized();
        $this->actingAsWithMfa($user);
        $this->getJson('/api/v1/tenants/'.$org->id.'/contracts/'.ContractRecord::factory()->create()->id)->assertNotFound();
        $this->getJson($path.'/financial-terms')->assertForbidden();
        $this->getJson($path.'?organization_id='.Str::uuid())->assertUnprocessable();
        OrganizationRoleAssignment::query()->where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->getJson($path)->assertForbidden();
        $this->assertDatabaseCount('organization_sensitive_access_events', 0);
        $this->assertDatabaseHas('identity_audit_events', ['action' => 'access.denied', 'target_id' => 'api.v1.contracts.show']);
    }

    public function test_unstructured_access_purpose_is_rejected_without_logging_its_content(): void
    {
        [$org, $user, $contract] = $this->documentScenario();
        $this->actingAsWithMfa($user);
        $this->withHeader('X-Access-Purpose', 'PRIVATE_REASON_WITH_CONTRACT_AMOUNT')->getJson('/api/v1/tenants/'.$org->id.'/contracts/'.$contract->id)
            ->assertUnprocessable()->assertJsonPath('errors.access_purpose.0', 'Choisissez un motif de consultation autorisé.');
        $this->assertDatabaseCount('organization_sensitive_access_events', 0);
    }

    public function test_audit_failure_returns_503_before_any_contract_content_is_served(): void
    {
        [$org, $user, $contract] = $this->documentScenario();
        $this->actingAsWithMfa($user);
        $this->mock(TenantSensitiveAccessJournal::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Untrusted failure content'));
        $response = $this->getJson('/api/v1/tenants/'.$org->id.'/contracts/'.$contract->id)->assertStatus(503)
            ->assertJsonPath('code', 'ACCESS_AUDIT_UNAVAILABLE')->assertHeader('Retry-After', '30');
        $contents = $response->getContent();
        $this->assertIsString($contents);
        $this->assertStringNotContainsString($contract->title, $contents);
        $this->assertStringNotContainsString('Untrusted failure content', $contents);
    }

    public function test_link_creation_document_history_and_real_download_have_distinct_events_without_tokens(): void
    {
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $this->actingAsWithMfa($user);
        $base = '/api/v1/tenants/'.$org->id.'/contracts/'.$contract->id.'/documents/'.$document->id;
        $this->getJson($base.'/versions')->assertOk();
        $security = $base.'/versions/'.$version->id;
        $this->getJson($security.'/scans')->assertOk();
        $url = $this->postJson($security.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        $this->assertDatabaseHas('organization_sensitive_access_events', ['action' => 'contract.download_link_created', 'target_id' => $version->id]);
        $this->assertDatabaseMissing('organization_sensitive_access_events', ['action' => 'contract.document_downloaded']);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseHas('organization_sensitive_access_events', ['action' => 'contract.document_downloaded', 'target_id' => $version->id]);
        $this->assertDatabaseCount('organization_sensitive_access_events', 4);
        $encoded = json_encode(DB::table('organization_sensitive_access_events')->get(), JSON_THROW_ON_ERROR);
        foreach (['PRIVATE DOCUMENT', 'contrat.pdf', 'signature=', 'expires=', 'sha256', $version->sha256] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_download_audit_failure_does_not_disclose_pdf_bytes(): void
    {
        [$org, $user, $contract, $context, $document, $version, $scan] = $this->securityScenario();
        $this->approveScan($context, $scan);
        $this->actingAsWithMfa($user);
        $url = $this->postJson('/api/v1/tenants/'.$org->id.'/contracts/'.$contract->id.'/documents/'.$document->id.'/versions/'.$version->id.'/download-link',
            ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
        $this->mock(TenantSensitiveAccessJournal::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $response = $this->get($url)->assertStatus(503);
        $contents = $response->getContent();
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('%PDF', $contents);
    }

    public function test_sensitive_timeline_requires_audit_permission_and_keeps_cursor_bound_to_the_auditor_and_filter(): void
    {
        [$org, $user, $contract] = $this->documentScenario();
        $this->actingAsWithMfa($user);
        $this->getJson('/api/v1/tenants/'.$org->id.'/contracts/'.$contract->id)->assertOk();
        $path = '/api/v1/tenants/'.$org->id.'/audit-events?source=tenancy.sensitive';
        $this->getJson($path)->assertForbidden();
        $auditor = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $auditor, ['audit.view']);
        $this->actingAsWithMfa($auditor);
        $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.0.action', 'contract.viewed')->assertJsonPath('data.0.target.id', $contract->id)
            ->assertJsonMissingPath('data.0.title')->assertJsonPath('data.0.after', []);
        app(TenantSensitiveAccessJournal::class)->record($org->id,
            new SensitiveAccessData($user->id, SensitiveAccessAction::ContractViewed, $contract->id, (string) Str::uuid()));
        $response = $this->getJson($path.'&per_page=1')->assertOk()->assertJsonCount(1, 'data');
        $cursor = $response->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $this->getJson($path.'&per_page=1&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($path.'&action=contract.listed&cursor='.urlencode($cursor))->assertUnprocessable();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $auditor, ['audit.view']);
        $this->getJson('/api/v1/tenants/'.$other->id.'/audit-events?source=tenancy.sensitive')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tenants/'.$other->id.'/audit-events?source=tenancy.sensitive&cursor='.urlencode($cursor))->assertUnprocessable();
    }

    public function test_operator_exports_record_request_generation_and_read_without_free_form_reason(): void
    {
        Storage::fake('local');
        Queue::fake([BuildOrganizationExport::class]);
        config(['tenancy.cache_store' => 'database', 'filesystems.artifacts' => 'local']);
        $org = Organization::factory()->withMfaOwner()->create();
        $audit = new OrganizationAuditData($org->owner_user_id, 'PRIVATE_CONTRACT_DETAILS', (string) Str::uuid());
        $service = app(OrganizationPortabilityService::class);
        $operation = $service->requestExport($org->id, $audit);
        $service->generate($org->id, $operation->id);
        $service->generate($org->id, $operation->id);
        $stream = $service->read($org->id, $operation->id, $audit);
        fclose($stream);
        $this->assertDatabaseCount('organization_sensitive_access_events', 3);
        foreach (['tenant.export_requested', 'tenant.export_generated', 'tenant.export_read'] as $action) {
            $this->assertDatabaseHas('organization_sensitive_access_events', ['organization_id' => $org->id, 'action' => $action,
                'target_id' => $operation->id, 'actor_user_id' => $org->owner_user_id, 'reason_code' => 'operator_portability']);
        }
        $this->assertStringNotContainsString('PRIVATE_CONTRACT_DETAILS', json_encode(DB::table('organization_sensitive_access_events')->get(), JSON_THROW_ON_ERROR));
        Queue::assertPushed(BuildOrganizationExport::class, 1);
    }

    public function test_export_request_rolls_back_if_its_audit_cannot_be_saved(): void
    {
        Storage::fake('local');
        Queue::fake([BuildOrganizationExport::class]);
        config(['tenancy.cache_store' => 'database', 'filesystems.artifacts' => 'local']);
        $org = Organization::factory()->withMfaOwner()->create();
        $this->mock(TenantSensitiveAccessJournal::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        try {
            app(OrganizationPortabilityService::class)->requestExport($org->id,
                new OrganizationAuditData($org->owner_user_id, 'Export demandé', (string) Str::uuid()));
            $this->fail('The export request must roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('tenant_portability_operations', 0);
        Queue::assertNothingPushed();
    }

    public function test_export_read_closes_its_stream_when_auditing_fails(): void
    {
        Storage::fake('local');
        Queue::fake([BuildOrganizationExport::class]);
        config(['tenancy.cache_store' => 'database', 'filesystems.artifacts' => 'local']);
        $org = Organization::factory()->withMfaOwner()->create();
        $audit = new OrganizationAuditData($org->owner_user_id, 'Export', (string) Str::uuid());
        $service = app(OrganizationPortabilityService::class);
        $operation = $service->requestExport($org->id, $audit);
        $service->generate($org->id, $operation->id);
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        $this->mock(PortabilityArchive::class)->shouldReceive('read')->once()->andReturn($stream);
        $this->mock(TenantSensitiveAccessJournal::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        try {
            app(OrganizationPortabilityService::class)->read($org->id, $operation->id, $audit);
            $this->fail('The export must not leave the service.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertFalse(is_resource($stream));
    }

    public function test_access_history_is_append_only_and_registered_in_the_export_catalogue(): void
    {
        [$org, $user, $contract] = $this->documentScenario();
        app(TenantSensitiveAccessJournal::class)->record($org->id,
            new SensitiveAccessData($user->id, SensitiveAccessAction::ContractViewed, $contract->id, (string) Str::uuid()));
        $event = DB::table('organization_sensitive_access_events')->first();
        $this->assertNotNull($event);
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($event, $operation): void {
                    $query = DB::table('organization_sensitive_access_events')->where('id', $event->id);
                    $operation === 'delete' ? $query->delete() : $query->update(['target_id' => (string) Str::uuid()]);
                });
                $this->fail('Access history must be retained.');
            } catch (QueryException) {
                $this->assertDatabaseCount('organization_sensitive_access_events', 1);
            }
        }
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
        $migration = require database_path('migrations/2026_10_15_192732_create_organization_sensitive_access_events.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_amendment_reads_record_the_document_identifier_without_financial_content(): void
    {
        Storage::fake('local');
        Queue::fake([ScanContractDocument::class]);
        [$org, $user, $contract, $context] = $this->documentScenario(['administrative.read', 'administrative.write', 'administrative.validate', 'financial.read']);
        $original = $this->originalDocument($context, $contract);
        [, $signer] = $this->declareContractSignature($org, $contract, $context);
        $service = app(ContractDocumentService::class);
        $copy = $service->upload($signer, $contract->id, $original->id,
            ['contract_version' => $contract->version, 'purpose' => 'signed_copy', 'file' => $this->documentPdf()])['version'];
        $contract->refresh();
        $service->sign($signer, $contract->id, $original->id, ['contract_version' => $contract->version,
            'document_version_id' => $copy->id, 'signed_on' => now()->utc()->toDateString(), 'confirm_signed_copy' => true]);
        $contract->refresh();
        $amendment = $service->create($context, $contract->id, ['contract_version' => $contract->version, 'reference' => 'amendment',
            'kind' => 'amendment', 'title' => 'SECRET_AMENDMENT_TITLE', 'original_document_id' => $original->id])['document'];
        $this->actingAsWithMfa($user);
        $base = '/api/v1/tenants/'.$org->id.'/contracts/'.$contract->id.'/documents/'.$amendment->id;
        $this->getJson($base.'/financial-terms')->assertOk();
        $this->getJson($base.'/approvals')->assertOk();
        $this->assertDatabaseHas('organization_sensitive_access_events', ['action' => 'contract.amendment_financial_viewed', 'target_type' => 'contract_document', 'target_id' => $amendment->id]);
        $this->assertDatabaseHas('organization_sensitive_access_events', ['action' => 'contract.approvals_viewed', 'target_id' => $amendment->id]);
        $this->assertStringNotContainsString('SECRET_AMENDMENT_TITLE', json_encode(DB::table('organization_sensitive_access_events')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_export_read_rechecks_the_operator_after_storage_io(): void
    {
        Storage::fake('local');
        Queue::fake([BuildOrganizationExport::class]);
        config(['tenancy.cache_store' => 'database', 'filesystems.artifacts' => 'local']);
        $org = Organization::factory()->withMfaOwner()->create();
        $audit = new OrganizationAuditData($org->owner_user_id, 'Export', (string) Str::uuid());
        $service = app(OrganizationPortabilityService::class);
        $operation = $service->requestExport($org->id, $audit);
        $service->generate($org->id, $operation->id);
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        $this->mock(PortabilityArchive::class)->shouldReceive('read')->once()->andReturnUsing(function () use ($org, $stream) {
            User::query()->whereKey($org->owner_user_id)->update(['email_verified_at' => null]);

            return $stream;
        });
        try {
            app(OrganizationPortabilityService::class)->read($org->id, $operation->id, $audit);
            $this->fail('A revoked operator cannot receive the archive.');
        } catch (InvalidOrganizationAccountException) {
            $this->assertFalse(is_resource($stream));
        }
        $this->assertDatabaseMissing('organization_sensitive_access_events', ['action' => 'tenant.export_read']);
    }

    public function test_sql_rejects_an_inconsistent_audit_target(): void
    {
        [$org, $user, $contract] = $this->documentScenario();
        $data = new SensitiveAccessData($user->id, SensitiveAccessAction::ContractViewed, $contract->id, (string) Str::uuid());
        try {
            DB::transaction(fn () => DB::table('organization_sensitive_access_events')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $org->id, ...$data->attributes(), 'target_type' => 'organization_export']));
            $this->fail('The target must match the recorded action.');
        } catch (QueryException) {
            $this->assertDatabaseCount('organization_sensitive_access_events', 0);
        }
    }

    public function test_empty_migration_round_trip_keeps_existing_contracts(): void
    {
        $contract = ContractRecord::factory()->create();
        $migration = require database_path('migrations/2026_10_15_192732_create_organization_sensitive_access_events.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
    }

    public function test_metadata_rejects_a_free_form_reason_even_for_a_trusted_caller(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SensitiveAccessData(1, SensitiveAccessAction::ContractViewed, (string) Str::uuid(), (string) Str::uuid(), 'PRIVATE_NOTE');
    }
}
