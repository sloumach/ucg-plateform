<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Contracts\Application\Services\ContractDocumentService;
use App\Modules\Contracts\Application\Services\ContractFinancialService;
use App\Modules\Contracts\Application\Services\ContractService;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesContractDocuments;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ContractFinancialGovernanceTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesContractDocuments, ProvidesPeopleAccess;

    private const array ALL = ['administrative.read', 'administrative.write', 'administrative.validate', 'financial.read', 'financial.write', 'financial.validate'];

    /** @return array<string, array{list<string>, bool, bool}> */
    public static function permissions(): array
    {
        return ['sports' => [['sports.read'], false, false], 'finance only' => [['financial.read', 'financial.write'], false, false],
            'administration' => [['administrative.read', 'administrative.write', 'administrative.validate'], false, false],
            'read' => [['administrative.read', 'financial.read'], true, false],
            'write without read' => [['administrative.read', 'administrative.write', 'financial.write'], false, false],
            'read without admin write' => [['administrative.read', 'financial.read', 'financial.write'], true, false],
            'write' => [self::ALL, true, true]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_financial_policy_requires_explicit_organization_permissions(array $permissions, bool $read, bool $write): void
    {
        [$org, $user, , $context] = $this->documentScenario($permissions);
        $gate = Gate::forUser($user);
        $this->assertSame($read, $gate->allows('financialRead', [ContractRecord::class, $context]));
        $this->assertSame($write, $gate->allows('financialWrite', [ContractRecord::class, $context]));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('financialRead', [ContractRecord::class, $context]));
    }

    public function test_terms_are_exact_encrypted_and_absent_from_normal_responses_audit_and_exports(): void
    {
        [$org, $user, $contract] = $this->documentScenario(self::ALL);
        $this->actingAsWithMfa($user);
        $this->getJson($this->financePath($org, $contract))->assertOk()->assertJsonPath('data', null);
        $saved = $this->saveTerms($org, $contract);
        $this->assertSame('123456789012.99', $saved['terms']['remuneration']);
        $raw = DB::table('contract_financial_terms')->value('terms');
        $this->assertStringNotContainsString('123456789012.99', $raw);
        $this->assertStringNotContainsString('Modalité confidentielle', $raw);
        $normal = $this->getJson($this->path($org, $contract))->assertOk()->assertJsonMissingPath('data.terms')
            ->assertJsonPath('data.financial_terms_present', true)->assertJsonPath('data.financial_documents_restricted', true)->getContent();
        $this->assertIsString($normal);
        $this->assertStringNotContainsString('123456789012.99', $normal);
        $this->getJson('/api/v1/tenants/'.$org->id.'/contracts')->assertOk()->assertJsonMissingPath('data.0.terms');
        $audit = json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('123456789012.99', $audit);
        $this->assertStringContainsString('financial_terms_saved', $audit);
        $export = json_encode(iterator_to_array(app(PortabilityRepository::class)->rows($org->id, 1000)), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('contract_financial_terms', $export);
        $this->assertStringNotContainsString('123456789012.99', $export);
        $this->assertDatabaseCount('contract_financial_terms', 1);
    }

    public function test_administrative_coach_receives_no_financial_fields_or_direct_document_access(): void
    {
        Storage::fake('local');
        [$org, $user, $contract, $context] = $this->documentScenario(self::ALL);
        $document = $this->originalDocument($context, $contract);
        $version = app(ContractDocumentService::class)->upload($context, $contract->id, $document->id, ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf()])['version'];
        $contract->refresh();
        $this->actingAsWithMfa($user);
        $this->saveTerms($org, $contract);
        $coach = $this->actor($org, ['administrative.read', 'administrative.write', 'administrative.validate']);
        $this->actingAsWithMfa($coach);
        $this->getJson($this->path($org, $contract))->assertOk()->assertJsonMissingPath('data.terms');
        $this->getJson($this->financePath($org, $contract))->assertForbidden();
        $this->patchJson($this->financePath($org, $contract), [])->assertForbidden();
        $this->getJson($this->documentPath($org, $contract))->assertForbidden();
        $this->getJson($this->documentPath($org, $contract, $document->id).'/versions')->assertForbidden();
        $security = $this->documentPath($org, $contract, $document->id).'/versions/'.$version->id;
        $this->getJson($security.'/scans')->assertForbidden();
        $this->postJson($security.'/download-link', ['expected_scan_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('contract_financial_terms', 1);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidTerms(): array
    {
        return ['float amount' => [['remuneration' => 1.25], 'terms.remuneration'],
            'negative' => [['bonus' => '-1.00'], 'terms.bonus'], 'scientific' => [['bonus' => '1e3'], 'terms.bonus'],
            'overflow' => [['bonus' => '1000000000000.00'], 'terms.bonus'], 'precision' => [['bonus' => '1.001'], 'terms.bonus'],
            'percent' => [['prize_share_percent' => '100.01'], 'terms.prize_share_percent'],
            'currency' => [['currency' => 'eur'], 'terms.currency'], 'period' => [['remuneration_period' => 'daily'], 'terms.remuneration_period'],
            'no period' => [['remuneration_period' => null], 'terms.remuneration_period'],
            'period without amount' => [['remuneration' => null], 'terms.remuneration_period'],
            'unknown' => [['salary' => '100.00'], 'terms'],
            'long text' => [['payment_terms' => str_repeat('x', 2001)], 'terms.payment_terms']];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidTerms')]
    public function test_invalid_financial_terms_return_422_without_partial_changes(array $changes, string $field): void
    {
        [$org, $user, $contract] = $this->documentScenario(self::ALL);
        $this->actingAsWithMfa($user);
        $version = $contract->version;
        $this->patchJson($this->financePath($org, $contract), ['contract_version' => $version, 'expected_version' => null, 'terms' => [...$this->terms(), ...$changes]])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($version, $contract->refresh()->version);
        $this->assertDatabaseCount('contract_financial_terms', 0);
    }

    public function test_stale_financial_and_contract_versions_and_cross_tenant_ids_are_rejected(): void
    {
        [$org, $user, $contract] = $this->documentScenario(self::ALL);
        $this->actingAsWithMfa($user);
        $old = $contract->version;
        $terms = $this->saveTerms($org, $contract);
        $this->patchJson($this->financePath($org, $contract), ['contract_version' => $old, 'expected_version' => $terms['version'], 'terms' => $this->terms()])
            ->assertConflict()->assertJsonPath('code', 'CONTRACT_VERSION_STALE');
        $this->patchJson($this->financePath($org, $contract), ['contract_version' => $contract->version, 'expected_version' => null, 'terms' => $this->terms()])
            ->assertConflict()->assertJsonPath('code', 'CONTRACT_FINANCIAL_VERSION_STALE');
        $foreign = ContractRecord::factory()->create();
        $this->getJson($this->path($org, $foreign).'/financial-terms')->assertNotFound();
        $this->patchJson($this->path($org, $foreign).'/financial-terms', ['contract_version' => $foreign->version, 'expected_version' => null, 'terms' => $this->terms()])->assertNotFound();
        $this->assertDatabaseCount('contract_financial_terms', 1);
    }

    public function test_financial_approval_requires_read_and_validate_and_terms_freeze_after_approval(): void
    {
        [$org, $user, $contract, $context] = $this->documentScenario(self::ALL);
        $this->actingAsWithMfa($user);
        $terms = $this->saveTerms($org, $contract);
        $service = app(ContractService::class);
        $service->transition($context, $contract->id, ['version' => $contract->version, 'status' => 'in_review']);
        $contract->refresh();
        foreach ([['administrative.read', 'administrative.write', 'administrative.validate', 'financial.read'],
            ['administrative.read', 'administrative.write', 'administrative.validate', 'financial.validate']] as $permissions) {
            $this->actingAsWithMfa($this->actor($org, $permissions));
            $this->postJson($this->path($org, $contract).'/transitions', ['version' => $contract->version, 'status' => 'approved'])->assertForbidden();
        }
        $reviewer = $this->actor($org, self::ALL);
        $this->actingAsWithMfa($reviewer);
        $this->postJson($this->path($org, $contract).'/transitions', ['version' => $contract->version, 'status' => 'approved'])->assertOk()->assertJsonPath('data.approved_by', $reviewer->id);
        $contract->refresh();
        $this->patchJson($this->financePath($org, $contract), ['contract_version' => $contract->version, 'expected_version' => $terms['version'], 'terms' => $this->terms()])
            ->assertConflict()->assertJsonPath('code', 'CONTRACT_TERMS_FROZEN');
        $this->assertDatabaseCount('contract_transitions', 2);
    }

    public function test_signature_separation_requires_a_third_actor_and_is_frozen_after_approval(): void
    {
        [$org, $preparer, $contract, $context] = $this->documentScenario(self::ALL);
        $service = app(ContractService::class);
        $service->save($context, ['version' => $contract->version, 'signature_separation_required' => true], $contract->id);
        $contract->refresh();
        $service->transition($context, $contract->id, ['version' => $contract->version, 'status' => 'in_review']);
        $contract->refresh();
        $approver = $this->actor($org, self::ALL);
        $approvalContext = $this->personContext($org, $approver);
        foreach (['approved', 'sent'] as $status) {
            $service->transition($approvalContext, $contract->id, ['version' => $contract->version, 'status' => $status]);
            $contract->refresh();
        }
        foreach ([$preparer, $approver] as $actor) {
            $this->actingAsWithMfa($actor);
            $this->postJson($this->path($org, $contract).'/transitions', $this->signature($contract))->assertConflict()->assertJsonPath('code', 'CONTRACT_SIGNATURE_SEPARATION_REQUIRED');
        }
        $this->patchJson($this->path($org, $contract), ['version' => $contract->version, 'signature_separation_required' => false])->assertConflict();
        $signer = $this->actor($org, self::ALL);
        $this->actingAsWithMfa($signer);
        $this->postJson($this->path($org, $contract).'/transitions', $this->signature($contract))->assertOk()->assertJsonPath('data.signature_recorded_by', $signer->id);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'signed', 'signature_recorded_by' => $signer->id]);
    }

    public function test_financial_audit_failure_rolls_back_terms_and_preparation_version(): void
    {
        [$org, $user, $contract, $context] = $this->documentScenario(self::ALL);
        $before = $contract->version;
        $audit = $this->mock(TenantAuditJournal::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        try {
            app(ContractFinancialService::class)->save($context, $contract->id, ['contract_version' => $before, 'expected_version' => null, 'terms' => $this->terms()]);
            $this->fail('Financial terms require durable audit.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('contract_financial_terms', 0);
        $this->assertSame($before, $contract->refresh()->version);
    }

    #[Group('tenant-isolation')]
    public function test_revoked_permissions_in_captured_context_cannot_write_financial_terms(): void
    {
        [$org, $user, $contract, $context] = $this->documentScenario(self::ALL);
        OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->expectException(AuthorizationException::class);
        app(ContractFinancialService::class)->save($context, $contract->id, ['contract_version' => $contract->version, 'expected_version' => null, 'terms' => $this->terms()]);
    }

    public function test_amendment_requires_distinct_current_approval_and_reapproval_after_terms_change(): void
    {
        Storage::fake('local');
        [$org, $user, $contract, $context] = $this->documentScenario(self::ALL);
        $document = $this->originalDocument($context, $contract);
        [$signer, $signContext] = $this->declareContractSignature($org, $contract, $context);
        $service = app(ContractDocumentService::class);
        $copy = $service->upload($signContext, $contract->id, $document->id, ['contract_version' => $contract->version, 'purpose' => 'signed_copy', 'file' => $this->documentPdf()])['version'];
        $contract->refresh();
        $service->sign($signContext, $contract->id, $document->id, ['contract_version' => $contract->version, 'document_version_id' => $copy->id, 'signed_on' => now()->utc()->toDateString(), 'confirm_signed_copy' => true]);
        $contract->refresh();
        $amendment = $service->create($context, $contract->id, ['contract_version' => $contract->version, 'reference' => 'amendment', 'kind' => 'amendment',
            'title' => 'Avenant', 'original_document_id' => $document->id])['document'];
        $contract->refresh();
        $draft = $service->upload($context, $contract->id, $amendment->id, ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf('Draft')])['version'];
        $contract->refresh();
        $url = $this->documentPath($org, $contract, $amendment->id);
        $this->actingAsWithMfa($user);
        $financial = $this->saveTerms($org, $contract, $amendment->id);
        $this->postJson($url.'/approvals', ['contract_version' => $contract->version, 'document_version_id' => $draft->id, 'confirm_approval' => true])->assertConflict()->assertJsonPath('code', 'CONTRACT_SELF_APPROVAL_FORBIDDEN');
        $this->post($url.'/versions', ['contract_version' => $contract->version, 'purpose' => 'signed_copy', 'file' => $this->documentPdf('Signed')], ['Accept' => 'application/json'])->assertConflict()->assertJsonPath('code', 'CONTRACT_AMENDMENT_APPROVAL_REQUIRED');
        $reviewer = $this->actor($org, self::ALL);
        $this->actingAsWithMfa($reviewer);
        $this->postJson($url.'/approvals', ['contract_version' => $contract->version, 'document_version_id' => $draft->id, 'confirm_approval' => true])->assertCreated()->assertJsonPath('data.approved_by', $reviewer->id);
        $contract->refresh();
        $obsoleteCopy = $service->upload($this->personContext($org, $reviewer), $contract->id, $amendment->id,
            ['contract_version' => $contract->version, 'purpose' => 'signed_copy', 'file' => $this->documentPdf('Old signed copy')])['version'];
        $contract->refresh();
        $oldDraft = $draft;
        $draft = $service->upload($context, $contract->id, $amendment->id,
            ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf('New preparation')])['version'];
        $contract->refresh();
        $this->postJson($url.'/signature', ['contract_version' => $contract->version, 'document_version_id' => $obsoleteCopy->id,
            'signed_on' => now()->utc()->toDateString(), 'confirm_signed_copy' => true])->assertConflict()->assertJsonPath('code', 'CONTRACT_AMENDMENT_APPROVAL_REQUIRED');
        $this->postJson($url.'/approvals', ['contract_version' => $contract->version, 'document_version_id' => $oldDraft->id, 'confirm_approval' => true])
            ->assertConflict()->assertJsonPath('code', 'CONTRACT_AMENDMENT_DRAFT_REQUIRED');
        $this->postJson($url.'/approvals', ['contract_version' => $contract->version, 'document_version_id' => $draft->id, 'confirm_approval' => true])->assertCreated();
        $contract->refresh();
        $this->getJson($this->documentPath($org, $contract))->assertOk()->assertJsonPath('data.1.approval.approved_by', $reviewer->id);
        $this->actingAsWithMfa($user);
        $this->saveTerms($org, $contract, $amendment->id, $financial['version']);
        $this->getJson($this->documentPath($org, $contract))->assertOk()->assertJsonPath('data.1.approval', null);
        $this->actingAsWithMfa($reviewer);
        $this->postJson($url.'/approvals', ['contract_version' => $contract->version, 'document_version_id' => $draft->id, 'confirm_approval' => true])->assertCreated();
        $contract->refresh();
        $this->postJson($url.'/signature', ['contract_version' => $contract->version, 'document_version_id' => $obsoleteCopy->id,
            'signed_on' => now()->utc()->toDateString(), 'confirm_signed_copy' => true])->assertConflict()->assertJsonPath('code', 'CONTRACT_AMENDMENT_COPY_STALE');
        $this->getJson($url.'/approvals?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3)
            ->assertJsonStructure(['meta' => ['request_id']])->assertJsonMissingPath('data.0.financial_version');
        $signed = $service->upload($this->personContext($org, $reviewer), $contract->id, $amendment->id,
            ['contract_version' => $contract->version, 'purpose' => 'signed_copy', 'file' => $this->documentPdf('Signed')])['version'];
        $contract->refresh();
        $this->postJson($url.'/signature', ['contract_version' => $contract->version, 'document_version_id' => $signed->id,
            'signed_on' => now()->utc()->toDateString(), 'confirm_signed_copy' => true])->assertOk();
        $contract->refresh();
        $this->saveTerms($org, $contract, $amendment->id, null, false);
        $this->assertDatabaseCount('contract_amendment_approvals', 3);
        $this->assertDatabaseCount('contract_document_signatures', 2);
        $this->rejectSql(fn () => DB::table('contract_amendment_approvals')->delete());
        $this->rejectSql(fn () => DB::table('contract_approved_copies')->delete());
    }

    public function test_empty_governance_migration_round_trip_preserves_schema_and_original_guards(): void
    {
        $this->withoutRetentionGuards(function (): void {
            $this->assertDatabaseCount('contracts', 0);
            $migration = require database_path('migrations/2026_10_14_231650_add_contract_financial_governance.php');
            $migration->down();
            $migration->up();
            $this->assertSame([], app(TenantSchemaInspector::class)->violations());
            app(PortabilityRepository::class)->assertCatalog();
            $contract = ContractRecord::factory()->create();
            $this->rejectSql(fn () => DB::table('contracts')->where('id', $contract->id)->update(['status' => 'signed']));
        });
    }

    public function test_sql_cannot_move_or_change_frozen_financial_terms_or_erase_decisions(): void
    {
        [$org, , $contract, $context] = $this->documentScenario(self::ALL);
        $terms = app(ContractFinancialService::class)->save($context, $contract->id, ['contract_version' => $contract->version, 'expected_version' => null, 'terms' => $this->terms()])['terms'];
        $this->rejectSql(fn () => DB::table('contract_financial_terms')->where('id', $terms->id)->update(['scope_id' => (string) Str::uuid(), 'version' => (string) Str::uuid()]));
        $this->rejectSql(fn () => DB::table('contract_financial_terms')->where('id', $terms->id)->delete());
        $contract->refresh();
        $service = app(ContractService::class);
        $service->transition($context, $contract->id, ['version' => $contract->version, 'status' => 'in_review']);
        $contract->refresh();
        $reviewer = $this->actor($org, self::ALL);
        $service->transition($this->personContext($org, $reviewer), $contract->id, ['version' => $contract->version, 'status' => 'approved']);
        $this->rejectSql(fn () => DB::table('contract_financial_terms')->where('id', $terms->id)->update(['terms' => 'spoof', 'version' => (string) Str::uuid()]));
        $this->assertSame('123456789012.99', $terms->refresh()->terms['remuneration']);
        $migration = require database_path('migrations/2026_10_14_231650_add_contract_financial_governance.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve financial history.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('cannot discard', $error->getMessage());
        }
        $this->assertDatabaseCount('contract_financial_terms', 1);
    }

    /** @param \Closure(): mixed $operation */
    private function rejectSql(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('SQL must enforce the governance invariant.');
        } catch (QueryException $error) {
            $this->assertSame(DB::getDriverName() === 'pgsql' ? '23514' : '23000', $error->errorInfo[0] ?? null);
        }
    }

    /** @param list<string> $permissions */
    private function actor(Organization $org, array $permissions): User
    {
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);

        return $user;
    }

    /** @return array<string, string> */
    private function terms(): array
    {
        return ['currency' => 'EUR', 'remuneration' => '123456789012.99', 'remuneration_period' => 'monthly',
            'bonus' => '1500.01', 'prize_share_percent' => '12.50', 'payment_terms' => 'Modalité confidentielle'];
    }

    private function path(Organization $org, ContractRecord $contract): string
    {
        return '/api/v1/tenants/'.$org->id.'/contracts/'.$contract->id;
    }

    private function financePath(Organization $org, ContractRecord $contract, ?string $document = null): string
    {
        return ($document === null ? $this->path($org, $contract) : $this->documentPath($org, $contract, $document)).'/financial-terms';
    }

    /** @return array<string, mixed> */
    private function saveTerms(Organization $org, ContractRecord $contract, ?string $document = null, ?string $expected = null, bool $success = true): array
    {
        $response = $this->patchJson($this->financePath($org, $contract, $document), ['contract_version' => $contract->version, 'expected_version' => $expected, 'terms' => $this->terms()]);
        if (! $success) {
            $response->assertConflict();

            return [];
        }
        $response->assertOk()->assertJsonPath('data.terms.bonus', '1500.01');
        $contract->refresh();

        return $response->json('data');
    }

    /** @return array<string, mixed> */
    private function signature(ContractRecord $contract): array
    {
        return ['version' => $contract->version, 'status' => 'signed', 'signed_on' => now()->utc()->toDateString(),
            'signature_reference' => 'Original reçu', 'confirm_signature_received' => true];
    }
}
