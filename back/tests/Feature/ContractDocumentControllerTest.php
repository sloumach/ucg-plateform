<?php

namespace Tests\Feature;

use App\Modules\Contracts\Application\Services\ContractDocumentService;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesContractDocuments;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ContractDocumentControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesContractDocuments, ProvidesPeopleAccess;

    public function test_document_reads_return_401_for_guests_and_403_for_sporting_staff(): void
    {
        [$org, , $contract] = $this->documentScenario(['sports.read']);
        $this->getJson($this->documentPath($org, $contract))->assertUnauthorized();
        $user = User::findOrFail($contract->created_by);
        $this->actingAsWithMfa($user);
        $this->getJson($this->documentPath($org, $contract))->assertForbidden();
    }

    public function test_create_returns_201_and_keeps_private_title_encrypted_with_minimal_audit(): void
    {
        [$org, $user, $contract] = $this->documentScenario();
        $this->actingAsWithMfa($user);
        $result = $this->postJson($this->documentPath($org, $contract), ['contract_version' => $contract->version,
            'reference' => 'original', 'kind' => 'original', 'title' => 'PRIVATE TITLE'])->assertCreated()
            ->assertJsonPath('data.versions_count', 0)->assertJsonPath('data.signed_version', null)
            ->assertJsonPath('data.publication_status', 'private')->assertJsonMissingPath('data.organization_id');
        $this->assertNotSame($contract->version, $result->json('meta.contract_version'));
        $this->assertDatabaseCount('contract_documents', 1);
        $this->assertNotSame('PRIVATE TITLE', DB::table('contract_documents')->value('title'));
        $this->assertStringNotContainsString('PRIVATE TITLE', json_encode(DB::table('organization_audit_events')->get()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_upload_creates_numbered_immutable_versions_with_server_hash_and_no_download(): void
    {
        Storage::fake('local');
        [$org, $user, $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        $this->actingAsWithMfa($user);
        $file = $this->documentPdf('PRIVATE CONTENT', 'private-name.pdf');
        $first = $this->post($this->documentPath($org, $contract, $document->id).'/versions',
            ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.number', 1)->assertJsonPath('data.sha256', hash('sha256', $file->getContent()))
            ->assertJsonPath('data.scan_status', 'pending')->assertJsonPath('data.download_available', false)
            ->assertJsonMissingPath('data.artifact_id')->assertJsonMissingPath('data.url');
        $contract->refresh();
        $this->post($this->documentPath($org, $contract, $document->id).'/versions',
            ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf('REVISED CONTENT')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.number', 2);
        $this->assertDatabaseCount('contract_document_versions', 2);
        $raw = DB::table('contract_document_versions')->where('id', $first->json('data.id'))->firstOrFail();
        $this->assertNotSame('private-name.pdf', $raw->filename);
        $stored = Storage::disk('local')->get('tenants/'.$org->id.'/artifacts/'.$raw->artifact_id);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('PRIVATE CONTENT', $stored);
        $this->assertSame($file->getContent(), decrypt($stored, false));
        $this->getJson($this->documentPath($org, $contract, $document->id).'/versions?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.number', 2);
        $this->getJson($this->documentPath($org, $contract, $document->id).'/versions/'.$raw->id.'/download')->assertNotFound();
    }

    public function test_selecting_a_signed_version_preserves_exact_file_and_avenant_origin_without_changing_contract_terms(): void
    {
        Storage::fake('local');
        $this->freezeTime();
        [$org, , $contract, $preparer] = $this->documentScenario();
        $document = $this->originalDocument($preparer, $contract);
        [$user, $context] = $this->declareContractSignature($org, $contract, $preparer);
        $this->actingAsWithMfa($user);
        $version = app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
            ['contract_version' => $contract->version, 'purpose' => 'signed_copy', 'file' => $this->documentPdf()])['version'];
        $contract->refresh();
        $this->assertNotNull($contract->signed_on);
        $date = $contract->signed_on->format('Y-m-d');
        $signed = $this->postJson($this->documentPath($org, $contract, $document->id).'/signature',
            ['contract_version' => $contract->version, 'document_version_id' => $version->id, 'signed_on' => $date, 'confirm_signed_copy' => true])
            ->assertOk()->assertJsonPath('data.signed_version.id', $version->id)->assertJsonPath('data.signed_version.sha256', $version->sha256);
        $contract->refresh();
        $before = $contract->getRawOriginal('title');
        $amendment = $this->postJson($this->documentPath($org, $contract), ['contract_version' => $contract->version,
            'reference' => 'avenant-1', 'kind' => 'amendment', 'title' => 'Avenant indépendant',
            'original_document_id' => $document->id, 'effective_on' => '2027-01-01'])->assertCreated()
            ->assertJsonPath('data.original_document_id', $document->id)->json('data.id');
        $this->assertDatabaseHas('contract_documents', ['id' => $amendment, 'contract_id' => $contract->id, 'kind' => 'amendment']);
        $this->assertSame($before, $contract->refresh()->getRawOriginal('title'));
        $this->assertSame('signed', $contract->status);
        $this->assertFalse($signed->json('data.signed_version') === null);
        $this->post($this->documentPath($org, $contract, $document->id).'/versions',
            ['contract_version' => $contract->version, 'purpose' => 'signed_copy', 'file' => $this->documentPdf('Replacement')], ['Accept' => 'application/json'])
            ->assertConflict()->assertJsonPath('code', 'CONTRACT_DOCUMENT_SIGNED');
        $this->assertDatabaseCount('tenant_artifacts', 1);
    }

    public function test_cross_tenant_contract_document_and_origin_return_404_even_for_a_member_of_both(): void
    {
        [$org, $user, $contract] = $this->documentScenario();
        $foreign = ContractDocument::factory()->create();
        $other = Organization::findOrFail($foreign->organization_id);
        $this->grantPeopleAccess($other, $user, ['administrative.read', 'administrative.write']);
        $this->actingAsWithMfa($user);
        $this->getJson($this->documentPath($org, $contract, $foreign->id).'/versions')->assertNotFound();
        $foreignContract = ContractRecord::findOrFail($foreign->contract_id);
        $this->getJson($this->documentPath($org, $foreignContract))->assertNotFound();
        $this->postJson($this->documentPath($org, $contract), ['contract_version' => $contract->version, 'reference' => 'foreign',
            'kind' => 'amendment', 'title' => 'X', 'original_document_id' => $foreign->id])->assertNotFound();
        $this->assertDatabaseCount('contract_documents', 1);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidDocuments(): array
    {
        return ['unknown field' => [['amount' => 100], 'amount'], 'tenant spoof' => [['organization_id' => 'other'], 'organization_id'],
            'type' => [['kind' => ['original']], 'kind'], 'reference' => [['reference' => 'Bad ref'], 'reference'],
            'title' => [['title' => str_repeat('X', 161)], 'title'], 'missing origin' => [['kind' => 'amendment'], 'original_document_id'],
            'invalid effect' => [['effective_on' => '2026-02-30'], 'effective_on'],
            'effect on original' => [['effective_on' => '2027-01-01'], 'effective_on']];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidDocuments')]
    public function test_invalid_creation_returns_422_and_leaves_no_document(array $changes, string $field): void
    {
        [$org, $user, $contract] = $this->documentScenario();
        $this->actingAsWithMfa($user);
        $this->postJson($this->documentPath($org, $contract), [...['contract_version' => $contract->version, 'reference' => 'original', 'kind' => 'original', 'title' => 'X'], ...$changes])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('contract_documents', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidFiles(): array
    {
        return ['executable' => ['attack.php', '<?php echo 1;'], 'false PDF' => ['fake.pdf', 'not a pdf'],
            'wrong extension' => ['file.txt', "%PDF-1.4\n%%EOF\n"], 'unsafe filename' => ['bad:name.pdf', "%PDF-1.4\n%%EOF\n"],
            'long filename' => [str_repeat('x', 157).'.pdf', "%PDF-1.4\n%%EOF\n"], 'too large' => ['large.pdf', "%PDF-1.4\n".str_repeat('x', 5242880)]];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_file_returns_422_without_reserving_storage(string $filename, string $content): void
    {
        Storage::fake('local');
        [$org, $user, $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        $this->actingAsWithMfa($user);
        $this->post($this->documentPath($org, $contract, $document->id).'/versions', ['contract_version' => $contract->version,
            'purpose' => 'draft', 'file' => UploadedFile::fake()->createWithContent($filename, $content)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('tenant_artifacts', 0);
        Storage::disk('local')->assertDirectoryEmpty('tenants');
    }

    public function test_reader_cannot_create_and_writer_cannot_declare_or_upload_a_signed_copy(): void
    {
        [$org, $reader, $contract, $context] = $this->documentScenario(['administrative.read']);
        $this->actingAsWithMfa($reader);
        $this->postJson($this->documentPath($org, $contract), [])->assertForbidden();
        $writer = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $writer, ['administrative.read', 'administrative.write']);
        $this->actingAsWithMfa($writer);
        $this->postJson($this->documentPath($org, $contract, (string) Str::uuid()).'/signature', [])->assertForbidden();
        $this->post($this->documentPath($org, $contract, (string) Str::uuid()).'/versions', ['purpose' => 'signed_copy'], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_stale_version_returns_409_and_duplicate_original_is_not_replaced(): void
    {
        [$org, $user, $contract, $context] = $this->documentScenario();
        $old = $contract->version;
        $document = $this->originalDocument($context, $contract);
        $this->actingAsWithMfa($user);
        $body = ['contract_version' => $old, 'reference' => 'other', 'kind' => 'original', 'title' => 'Replacement'];
        $this->postJson($this->documentPath($org, $contract), $body)->assertConflict()->assertJsonPath('code', 'CONTRACT_VERSION_STALE');
        $this->postJson($this->documentPath($org, $contract), [...$body, 'contract_version' => $contract->version])
            ->assertConflict()->assertJsonPath('code', 'CONTRACT_DOCUMENT_REFERENCE_ALREADY_EXISTS');
        $this->assertDatabaseCount('contract_documents', 1);
        $this->assertDatabaseHas('contract_documents', ['id' => $document->id]);
    }

    public function test_mfa_and_unconfirmed_tenant_block_mutations_before_creation(): void
    {
        [$org, $user, $contract] = $this->documentScenario();
        $this->actingAsWithMfa($user);
        $this->withSession([MfaSession::PROOF_KEY => null])->postJson($this->documentPath($org, $contract), [])->assertForbidden();
        $this->actingAsWithMfa($user);
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $org->id, 'actor_user_id' => $user->id, 'revision' => $revision, 'confirmed' => false]])
            ->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision)->postJson($this->documentPath($org, $contract), [])
            ->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->assertDatabaseCount('contract_documents', 0);
    }

    public function test_invalid_signature_confirmation_future_date_or_unknown_fields_return_422_and_foreign_version_404(): void
    {
        Storage::fake('local');
        $this->freezeTime();
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        [$validator, $validatorContext] = $this->declareContractSignature($org, $contract, $context);
        $version = app(ContractDocumentService::class)->upload($validatorContext, $contract->id, $document->id,
            ['contract_version' => $contract->version, 'purpose' => 'signed_copy', 'file' => $this->documentPdf()])['version'];
        $contract->refresh();
        $this->actingAsWithMfa($validator);
        $body = ['contract_version' => $contract->version, 'document_version_id' => $version->id,
            'signed_on' => now()->utc()->toDateString(), 'confirm_signed_copy' => true];
        $path = $this->documentPath($org, $contract, $document->id).'/signature';
        $this->postJson($path, [...$body, 'confirm_signed_copy' => false])->assertUnprocessable()->assertJsonValidationErrors('confirm_signed_copy')
            ->assertJsonPath('errors.confirm_signed_copy.0', __('contract_documents.confirm'));
        $this->postJson($path, [...$body, 'signed_on' => now()->utc()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('signed_on');
        $this->postJson($path, [...$body, 'sha256' => str_repeat('a', 64)])->assertUnprocessable()->assertJsonValidationErrors('sha256');
        $this->postJson($path, [...$body, 'document_version_id' => (string) Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('contract_document_signatures', 0);
        $this->assertSame($body['contract_version'], $contract->refresh()->version);
    }

    public function test_lists_are_bounded_and_reader_can_inspect_an_existing_signed_contract_without_invented_versions(): void
    {
        [$org, , $contract, $context] = $this->documentScenario();
        [$validator] = $this->declareContractSignature($org, $contract, $context);
        $this->actingAsWithMfa($validator);
        $this->getJson($this->documentPath($org, $contract))->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('contract_document_versions', 0);
        $this->getJson($this->documentPath($org, $contract).'?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson($this->documentPath($org, $contract).'?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson($this->documentPath($org, $contract).'?organization_id=other')->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->postJson($this->documentPath($org, $contract), ['contract_version' => $contract->version, 'reference' => 'original',
            'kind' => 'original', 'title' => 'Original historique'])->assertCreated()->assertJsonPath('data.signed_version', null);
        $this->assertSame('signed', $contract->refresh()->status);
        $this->assertDatabaseCount('contract_document_signatures', 0);
    }

    public function test_uppercase_route_identifiers_resolve_owned_contract_and_document_without_altering_foreign_keys(): void
    {
        Storage::fake('local');
        [$org, , $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        [$validator] = $this->declareContractSignature($org, $contract, $context);
        $this->actingAsWithMfa($validator);
        $path = '/api/v1/tenants/'.$org->id.'/contracts/'.strtoupper($contract->id).'/documents/'.strtoupper($document->id);
        $version = $this->post($path.'/versions', ['contract_version' => $contract->version, 'purpose' => 'signed_copy',
            'file' => $this->documentPdf()], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $contract->refresh();
        $this->postJson($path.'/signature', ['contract_version' => $contract->version, 'document_version_id' => strtoupper($version),
            'signed_on' => now()->utc()->toDateString(), 'confirm_signed_copy' => true])->assertOk()->assertJsonPath('data.signed_version.id', $version);
        $this->assertDatabaseHas('contract_document_versions', ['id' => $version, 'contract_id' => $contract->id, 'document_id' => $document->id]);
    }
}
