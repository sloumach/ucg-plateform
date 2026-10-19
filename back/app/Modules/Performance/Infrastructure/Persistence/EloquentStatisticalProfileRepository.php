<?php

namespace App\Modules\Performance\Infrastructure\Persistence;

use App\Modules\Performance\Domain\Aggregation\StatisticalCalculator;
use App\Modules\Performance\Domain\Contracts\StatisticalProfileRepository;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Pagination\LengthAwarePaginator;

final class EloquentStatisticalProfileRepository implements StatisticalProfileRepository
{
    /** @return Builder<StatisticalObservation> */
    private function visible(string $organization, Query $matches): Builder
    {
        return StatisticalObservation::query()->where('organization_id', $organization)
            ->where(fn (Builder $query) => $query->whereNull('match_id')->orWhereIn('match_id', $matches));
    }

    public function candidates(string $organization, Query $matches, array $people, array $versions, array $filters): array
    {
        $query = $this->visible($organization, $matches)->whereIn('person_id', $people)->whereIn('metric_revision_id', $versions)
            ->where('period_start', '>=', $filters['from'])->where('period_end', '<=', $filters['to']);
        if (isset($filters['source_kind'])) {
            $query->where('source_kind', $filters['source_kind']);
        }

        // Context and labels are encrypted: bound the indexed candidates before decrypting and filtering.
        return array_values($query->with('current')->orderBy('person_id')->orderBy('period_start')->orderBy('id')
            ->limit(count($people) * StatisticalCalculator::MAX_SOURCES + 1)->get()->all());
    }

    public function people(string $organization, Query $matches, int $page): LengthAwarePaginator
    {
        return $this->visible($organization, $matches)->select('person_id')->distinct()->orderBy('person_id')
            ->paginate(10, ['person_id'], 'page', $page)->through(fn (StatisticalObservation $row): string => $row->person_id);
    }
}
