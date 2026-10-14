<?php

namespace Tests\Fixtures;

use App\Modules\Contracts\Application\Services\ContractDocumentScanProcessor;
use App\Modules\Contracts\Application\Services\ContractDocumentService;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentScanner;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractDocumentScan;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Domain\ScanVerdict;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

trait ProvidesDocumentSecurity
{
    use ProvidesContractDocuments, ProvidesPeopleAccess;

    /** @return array{Organization, User, ContractRecord, TenantContext, ContractDocument, ContractDocumentVersion, ContractDocumentScan} */
    protected function securityScenario(): array
    {
        Storage::fake('local');
        Queue::fake();
        [$org, $user, $contract, $context] = $this->documentScenario();
        $document = $this->originalDocument($context, $contract);
        $version = app(ContractDocumentService::class)->upload($context, $contract->id, $document->id,
            ['contract_version' => $contract->version, 'purpose' => 'draft', 'file' => $this->documentPdf()])['version'];
        $scan = ContractDocumentScan::query()->where('document_version_id', $version->id)->firstOrFail();

        return [$org, $user, $contract, $context, $document, $version, $scan];
    }

    protected function approveScan(TenantContext $context, ContractDocumentScan $scan): void
    {
        $this->mock(ContractDocumentScanner::class)->shouldReceive('scan')->once()->andReturn(new ScanVerdict('clean', engine: 'ClamAV test/123/fresh'));
        app(ContractDocumentScanProcessor::class)->process($context, $scan->id);
        $scan->refresh();
    }
}
