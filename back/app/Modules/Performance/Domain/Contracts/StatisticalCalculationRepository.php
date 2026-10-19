<?php

namespace App\Modules\Performance\Domain\Contracts;

use App\Modules\Performance\Domain\Models\StatisticalCalculation;
use App\Modules\Performance\Domain\Models\StatisticalCalculationRevision;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

interface StatisticalCalculationRepository
{
    /** @param list<string> $ids
     * @return list<StatisticalObservation> */
    public function observations(string $organization, array $ids, Builder $matches): array;

    /** @return list<string> */
    public function sourceIds(string $organization, string $calculation): array;

    public function find(string $organization, int $actor, string $id, Builder $matches, bool $lock = false): StatisticalCalculation;

    public function metadata(string $organization, int $actor, string $id, bool $lock = false): StatisticalCalculation;

    public function fingerprint(string $organization, int $actor, string $fingerprint): ?StatisticalCalculation;

    public function count(string $organization, int $actor): int;

    /** @param array<string,mixed> $attributes
     * @param list<string> $sources */
    public function create(array $attributes, array $sources): StatisticalCalculation;

    /** @param array<string,mixed> $attributes */
    public function save(StatisticalCalculation $calculation, array $attributes): void;

    /** @param array<string,mixed> $attributes */
    public function append(array $attributes): StatisticalCalculationRevision;

    /** @return LengthAwarePaginator<int,StatisticalCalculation> */
    public function paginate(string $organization, int $actor, Builder $matches, int $page): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int,StatisticalCalculationRevision> */
    public function history(string $organization, string $calculation, int $page): LengthAwarePaginator;

    public function invalidate(string $organization, string $observation, string $version): int;

    /** @return list<StatisticalCalculation> */
    public function affected(string $organization, string $observation, ?string $after): array;
}
