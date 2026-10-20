<?php

namespace App\Modules\Contracts\Infrastructure\Persistence;

use App\Modules\Contracts\Domain\Contracts\ContractDocumentRepository;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractDocumentSignature;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use App\Support\Data\SearchPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentContractDocumentRepository implements ContractDocumentRepository
{
    public function search(string $org, string $term, ?string $after, int $size, bool $financial): SearchPage
    {
        $query = ContractDocument::query()->where('organization_id', $org);
        // Any financial terms on the parent contract restrict every document's metadata.
        if (! $financial) {
            $query->whereNotExists(fn (\Illuminate\Database\Query\Builder $q) => $q->selectRaw('1')->from('contract_financial_terms as financial')
                ->where('financial.organization_id', $org)->whereColumn('financial.contract_id', 'contract_documents.contract_id'));
        }
        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        return SearchPage::scan($query->orderBy('id')->limit(SearchPage::SCAN_LIMIT + 1)->get(['id', 'contract_id', 'title', 'reference']), $term, $size,
            fn (ContractDocument $row): array => ['id' => $row->id, 'label' => $row->title, 'reference' => $row->reference, 'parent_id' => $row->contract_id]);
    }

    /** @return Builder<ContractDocument> */
    private function query(string $org, string $contract): Builder
    {
        return ContractDocument::query()->where('organization_id', strtolower($org))->where('contract_id', strtolower($contract))
            ->with(['signature' => fn ($query) => $query->where('contract_document_signatures.organization_id', strtolower($org))])
            ->with([
                'latestDraft' => fn ($query) => $query->where('organization_id', strtolower($org))->select(['id', 'document_id', 'created_by']),
                'latestApproval' => fn ($query) => $query->where('organization_id', strtolower($org)),
                'financialTerms' => fn ($query) => $query->where('organization_id', strtolower($org))->select(['id', 'scope_id', 'version', 'prepared_by']),
            ])
            ->withCount(['versions' => fn (Builder $query) => $query->where('contract_document_versions.organization_id', strtolower($org))]);
    }

    public function paginate(string $organizationId, string $contractId, array $filters): LengthAwarePaginator
    {
        return $this->query($organizationId, $contractId)->orderBy('created_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $organizationId, string $contractId, string $id): ContractDocument
    {
        return $this->query($organizationId, $contractId)->findOrFail(strtolower($id));
    }

    public function count(string $organizationId, string $contractId): int
    {
        return ContractDocument::query()->where('organization_id', strtolower($organizationId))->where('contract_id', strtolower($contractId))->count();
    }

    public function create(array $attributes): ContractDocument
    {
        $record = new ContractDocument;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function versions(string $organizationId, string $contractId, string $documentId, array $filters): LengthAwarePaginator
    {
        return $this->versionQuery($organizationId, $contractId, $documentId)->orderByDesc('number')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    /** @return Builder<ContractDocumentVersion> */
    private function versionQuery(string $org, string $contract, string $document): Builder
    {
        return ContractDocumentVersion::query()->with(['latestScan' => fn ($query) => $query->where('contract_document_scans.organization_id', strtolower($org))])->where('organization_id', strtolower($org))
            ->where('contract_id', strtolower($contract))->where('document_id', strtolower($document));
    }

    public function version(string $organizationId, string $contractId, string $documentId, string $id): ContractDocumentVersion
    {
        return $this->versionQuery($organizationId, $contractId, $documentId)->findOrFail(strtolower($id));
    }

    public function addVersion(array $attributes): ContractDocumentVersion
    {
        $record = new ContractDocumentVersion;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function sign(array $attributes): void
    {
        (new ContractDocumentSignature)->forceFill($attributes)->save();
    }
}
