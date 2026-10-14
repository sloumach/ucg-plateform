<?php

namespace App\Modules\Contracts\Domain\Contracts;

use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ContractDocumentRepository
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ContractDocument>
     */
    public function paginate(string $organizationId, string $contractId, array $filters): LengthAwarePaginator;

    public function find(string $organizationId, string $contractId, string $id): ContractDocument;

    public function count(string $organizationId, string $contractId): int;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): ContractDocument;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ContractDocumentVersion>
     */
    public function versions(string $organizationId, string $contractId, string $documentId, array $filters): LengthAwarePaginator;

    public function version(string $organizationId, string $contractId, string $documentId, string $id): ContractDocumentVersion;

    /** @param array<string, mixed> $attributes */
    public function addVersion(array $attributes): ContractDocumentVersion;

    /** @param array<string, mixed> $attributes */
    public function sign(array $attributes): void;
}
