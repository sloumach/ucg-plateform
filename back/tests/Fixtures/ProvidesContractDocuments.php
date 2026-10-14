<?php

namespace Tests\Fixtures;

use App\Modules\Contracts\Application\Services\ContractDocumentService;
use App\Modules\Contracts\Application\Services\ContractService;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractParty;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Http\UploadedFile;

trait ProvidesContractDocuments
{
    /** @param list<string> $permissions
     * @return array{Organization, User, ContractRecord, TenantContext}
     */
    protected function documentScenario(array $permissions = ['administrative.read', 'administrative.write', 'administrative.validate']): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $person = Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $org->id]);
        $contract = ContractRecord::factory()->create(['organization_id' => $org->id, 'created_by' => $user->id, 'prepared_by' => $user->id,
            'responsible_person_id' => $person->id,
            'starts_on' => '2000-01-01', 'ends_on' => '2099-12-31']);
        (new ContractParty)->forceFill(['organization_id' => $org->id,
            'contract_id' => $contract->id, 'kind' => 'person', 'person_id' => $person->id, 'name' => 'Partie contractante', 'position' => 1])->save();

        return [$org, $user, $contract, $this->personContext($org, $user)];
    }

    protected function originalDocument(TenantContext $context, ContractRecord $contract): ContractDocument
    {
        $result = app(ContractDocumentService::class)->create($context, $contract->id,
            ['contract_version' => $contract->version, 'reference' => 'original', 'kind' => 'original', 'title' => 'Document confidentiel']);
        $contract->refresh();

        return $result['document'];
    }

    /** @return array{User, TenantContext} */
    protected function declareContractSignature(Organization $org, ContractRecord $contract, TenantContext $preparer): array
    {
        $service = app(ContractService::class);
        $service->transition($preparer, $contract->id, ['version' => $contract->version, 'status' => 'in_review']);
        $contract->refresh();
        $validator = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $validator, ['administrative.read', 'administrative.write', 'administrative.validate']);
        $context = $this->personContext($org, $validator);
        foreach (['approved', 'sent', 'signed'] as $status) {
            $service->transition($context, $contract->id, ['version' => $contract->version, 'status' => $status,
                ...($status === 'signed' ? ['signed_on' => now()->utc()->toDateString(), 'signature_reference' => 'Original signé reçu', 'confirm_signature_received' => true] : [])]);
            $contract->refresh();
        }

        return [$validator, $context];
    }

    protected function documentPdf(string $text = 'PRIVATE DOCUMENT', string $filename = 'contrat.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% ".$text."\n%%EOF\n");
    }

    protected function documentPath(Organization $org, ContractRecord $contract, ?string $documentId = null): string
    {
        return '/api/v1/tenants/'.$org->id.'/contracts/'.$contract->id.'/documents'.($documentId === null ? '' : '/'.$documentId);
    }
}
