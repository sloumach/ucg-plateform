<?php

namespace App\Modules\Performance\Domain\Contracts;

use App\Modules\Performance\Domain\Models\ObservationRevision;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

interface ObservationRepository
{
    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,StatisticalObservation> */
    public function paginate(string $org, Builder $matches, array $filters): LengthAwarePaginator;

    public function find(string $org, string $id, Builder $matches, bool $lock = false): StatisticalObservation;

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): StatisticalObservation;

    /** @param array<string,mixed> $attributes */
    public function append(array $attributes): ObservationRevision;

    /** @return LengthAwarePaginator<int,ObservationRevision> */
    public function history(string $org, string $id, int $page, int $size): LengthAwarePaginator;

    public function version(string $org, string $id, string $version): ObservationRevision;
}
