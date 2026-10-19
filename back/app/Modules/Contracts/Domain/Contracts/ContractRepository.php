<?php

namespace App\Modules\Contracts\Domain\Contracts;

use App\Modules\Contracts\Domain\Models\ContractObligation;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Domain\Models\ContractTransition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ContractRepository
{
    /** @return LengthAwarePaginator<int,ContractRecord> */
    public function expiring(string $organizationId, string $from, string $to, int $page): LengthAwarePaginator;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ContractRecord>
     */
    public function paginate(string $organizationId, array $filters): LengthAwarePaginator;

    public function find(string $organizationId, string $id, bool $lock = false): ContractRecord;

    public function count(string $organizationId): int;

    /** @param array<string, mixed> $attributes */
    public function save(?ContractRecord $contract, array $attributes): ContractRecord;

    /** @param list<array<string, mixed>> $parties */
    public function replaceParties(ContractRecord $contract, array $parties): void;

    /** @param list<array<string, mixed>> $obligations */
    public function replaceObligations(ContractRecord $contract, array $obligations): void;

    public function obligation(string $organizationId, string $contractId, string $id): ContractObligation;

    public function saveObligation(ContractObligation $obligation, string $status, string $version): void;

    /** @param array<string, mixed> $attributes */
    public function recordTransition(array $attributes): void;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ContractTransition>
     */
    public function transitions(string $organizationId, string $contractId, array $filters): LengthAwarePaginator;
}
