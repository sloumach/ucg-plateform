<?php

namespace App\Modules\Performance\Domain\Contracts;

use App\Modules\Performance\Domain\Models\StatisticalObservation;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

interface StatisticalProfileRepository
{
    /** @param list<string> $people
     * @param list<string> $versions
     * @param array<string,mixed> $filters
     * @return list<StatisticalObservation> */
    public function candidates(string $organization, Builder $matches, array $people, array $versions, array $filters): array;

    /** @return LengthAwarePaginator<int,string> */
    public function people(string $organization, Builder $matches, int $page): LengthAwarePaginator;
}
