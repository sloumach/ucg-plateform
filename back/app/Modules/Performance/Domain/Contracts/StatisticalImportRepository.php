<?php

namespace App\Modules\Performance\Domain\Contracts;

use App\Modules\Performance\Domain\Models\StatisticalImport;
use Illuminate\Pagination\LengthAwarePaginator;

interface StatisticalImportRepository
{
    public function find(string $organization, int $actor, string $id): StatisticalImport;

    public function fingerprint(string $organization, int $actor, string $fingerprint): ?StatisticalImport;

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): StatisticalImport;

    /** @return LengthAwarePaginator<int,StatisticalImport> */
    public function paginate(string $organization, int $actor, int $page): LengthAwarePaginator;
}
