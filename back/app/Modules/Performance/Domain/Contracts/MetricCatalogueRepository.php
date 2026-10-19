<?php

namespace App\Modules\Performance\Domain\Contracts;

use App\Modules\Performance\Domain\Models\MetricDefinition;
use App\Modules\Performance\Domain\Models\MetricRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MetricCatalogueRepository
{
    /** @return array{version:string,number:int} */
    public function state(bool $lock = false): array;

    public function count(): int;

    public function find(string $id): MetricDefinition;

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,MetricDefinition> */
    public function paginate(array $filters): LengthAwarePaginator;

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): MetricDefinition;

    /** @param array<string,mixed> $attributes */
    public function append(array $attributes): MetricRevision;

    /** @return LengthAwarePaginator<int,MetricRevision> */
    public function history(string $metric, int $page, int $size): LengthAwarePaginator;

    public function revision(string $metric, string $revision): MetricRevision;
}
