<?php

namespace App\Modules\Contracts\Domain\Contracts;

use App\Modules\Contracts\Domain\Models\ContractDocumentScan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ContractDocumentScanRepository
{
    public function latest(string $org, string $versionId, bool $lock = false): ?ContractDocumentScan;

    public function find(string $org, string $id, bool $lock = false): ContractDocumentScan;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): ContractDocumentScan;

    /** @param array<string, mixed> $attributes */
    public function update(ContractDocumentScan $scan, array $attributes): void;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ContractDocumentScan>
     */
    public function paginate(string $org, string $versionId, array $filters): LengthAwarePaginator;
}
