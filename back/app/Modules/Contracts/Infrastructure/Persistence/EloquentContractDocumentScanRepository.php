<?php

namespace App\Modules\Contracts\Infrastructure\Persistence;

use App\Modules\Contracts\Domain\Contracts\ContractDocumentScanRepository;
use App\Modules\Contracts\Domain\Models\ContractDocumentScan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class EloquentContractDocumentScanRepository implements ContractDocumentScanRepository
{
    public function latest(string $org, string $versionId, bool $lock = false): ?ContractDocumentScan
    {
        return ContractDocumentScan::query()->where('organization_id', strtolower($org))->where('document_version_id', strtolower($versionId))
            ->orderByDesc('attempt')->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public function find(string $org, string $id, bool $lock = false): ContractDocumentScan
    {
        return ContractDocumentScan::query()->where('organization_id', strtolower($org))->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail(strtolower($id));
    }

    public function create(array $attributes): ContractDocumentScan
    {
        $scan = new ContractDocumentScan;
        $scan->forceFill($attributes)->save();

        return $scan;
    }

    public function update(ContractDocumentScan $scan, array $attributes): void
    {
        $scan->forceFill($attributes)->save();
    }

    public function paginate(string $org, string $versionId, array $filters): LengthAwarePaginator
    {
        return ContractDocumentScan::query()->where('organization_id', strtolower($org))->where('document_version_id', strtolower($versionId))
            ->orderByDesc('attempt')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }
}
