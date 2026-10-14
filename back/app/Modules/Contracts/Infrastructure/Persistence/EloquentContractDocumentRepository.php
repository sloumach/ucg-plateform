<?php

namespace App\Modules\Contracts\Infrastructure\Persistence;

use App\Modules\Contracts\Domain\Contracts\ContractDocumentRepository;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractDocumentSignature;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentContractDocumentRepository implements ContractDocumentRepository
{
    /** @return Builder<ContractDocument> */
    private function query(string $org, string $contract): Builder
    {
        return ContractDocument::query()->where('organization_id', strtolower($org))->where('contract_id', strtolower($contract))
            ->with(['signature' => fn ($query) => $query->where('contract_document_signatures.organization_id', strtolower($org))])
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
