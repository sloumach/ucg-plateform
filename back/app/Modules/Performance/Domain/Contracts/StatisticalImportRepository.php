<?php

namespace App\Modules\Performance\Domain\Contracts;

use App\Modules\Performance\Domain\Models\StatisticalImport;
use App\Modules\Performance\Domain\Models\StatisticalImportExecution;
use App\Modules\Performance\Domain\Models\StatisticalImportReceipt;
use Illuminate\Pagination\LengthAwarePaginator;

interface StatisticalImportRepository
{
    public function find(string $organization, int $actor, string $id, bool $lock = false): StatisticalImport;

    /** @param array<string,mixed> $attributes */
    public function createExecution(array $attributes): StatisticalImportExecution;

    /** @param array<string,mixed> $attributes */
    public function saveExecution(StatisticalImportExecution $execution, array $attributes): void;

    public function receipt(string $organization, string $key): ?StatisticalImportReceipt;

    /** @param array<string,mixed> $attributes */
    public function createReceipt(array $attributes): void;

    public function fingerprint(string $organization, int $actor, string $fingerprint): ?StatisticalImport;

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): StatisticalImport;

    /** @return LengthAwarePaginator<int,StatisticalImport> */
    public function paginate(string $organization, int $actor, int $page): LengthAwarePaginator;
}
