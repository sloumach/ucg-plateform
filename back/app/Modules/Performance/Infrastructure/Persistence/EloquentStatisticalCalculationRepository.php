<?php

namespace App\Modules\Performance\Infrastructure\Persistence;

use App\Modules\Performance\Domain\Aggregation\StatisticalCalculator;
use App\Modules\Performance\Domain\Contracts\StatisticalCalculationRepository;
use App\Modules\Performance\Domain\Models\StatisticalCalculation;
use App\Modules\Performance\Domain\Models\StatisticalCalculationRevision;
use App\Modules\Performance\Domain\Models\StatisticalCalculationSource;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class EloquentStatisticalCalculationRepository implements StatisticalCalculationRepository
{
    public function observations(string $organization, array $ids, Query $matches): array
    {
        return array_values(StatisticalObservation::query()->where('organization_id', $organization)->whereIn('id', $ids)
            ->where(fn (Builder $query) => $query->whereNull('match_id')->orWhereIn('match_id', $matches))
            ->with('current')->orderBy('id')->limit(StatisticalCalculator::MAX_SOURCES)->get()->all());
    }

    public function sourceIds(string $organization, string $calculation): array
    {
        return array_values(StatisticalCalculationSource::query()->where('organization_id', $organization)->where('calculation_id', $calculation)
            ->orderBy('observation_id')->limit(StatisticalCalculator::MAX_SOURCES)->get()->map(fn (StatisticalCalculationSource $row): string => $row->observation_id)->all());
    }

    /** @return Builder<StatisticalCalculation> */
    private function visible(string $organization, int $actor, Query $matches): Builder
    {
        return StatisticalCalculation::query()->where('organization_id', $organization)->where('actor_user_id', $actor)
            ->whereNotExists(fn (Query $query) => $query->selectRaw('1')->from('statistical_calculation_sources as scs')
                ->join('statistical_observations as o', 'o.id', '=', 'scs.observation_id')
                ->whereColumn('scs.calculation_id', 'statistical_calculations.id')->where('scs.organization_id', $organization)
                ->where('o.organization_id', $organization)->whereNotNull('o.match_id')->whereNotIn('o.match_id', $matches))->with('current');
    }

    public function find(string $organization, int $actor, string $id, Query $matches, bool $lock = false): StatisticalCalculation
    {
        return $this->visible($organization, $actor, $matches)->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail(strtolower($id));
    }

    public function metadata(string $organization, int $actor, string $id, bool $lock = false): StatisticalCalculation
    {
        return StatisticalCalculation::query()->where('organization_id', $organization)->where('actor_user_id', $actor)
            ->with('current')->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail(strtolower($id));
    }

    public function fingerprint(string $organization, int $actor, string $fingerprint): ?StatisticalCalculation
    {
        return StatisticalCalculation::query()->where('organization_id', $organization)->where('actor_user_id', $actor)->where('fingerprint', $fingerprint)->first();
    }

    public function count(string $organization, int $actor): int
    {
        return StatisticalCalculation::query()->where('organization_id', $organization)->where('actor_user_id', $actor)->count();
    }

    public function create(array $attributes, array $sources): StatisticalCalculation
    {
        $calculation = new StatisticalCalculation;
        $calculation->forceFill($attributes)->save();
        $links = array_map(fn (string $source): array => ['id' => (string) Str::uuid(),
            'organization_id' => $calculation->organization_id, 'calculation_id' => $calculation->id, 'observation_id' => $source], $sources);
        foreach (array_chunk($links, 100) as $chunk) {
            StatisticalCalculationSource::query()->insert($chunk);
        }

        return $calculation;
    }

    public function save(StatisticalCalculation $calculation, array $attributes): void
    {
        $calculation->forceFill($attributes)->save();
    }

    public function append(array $attributes): StatisticalCalculationRevision
    {
        $revision = new StatisticalCalculationRevision;
        $revision->forceFill($attributes)->save();

        return $revision;
    }

    public function paginate(string $organization, int $actor, Query $matches, int $page): LengthAwarePaginator
    {
        return $this->visible($organization, $actor, $matches)->orderByDesc('id')->paginate(10, ['*'], 'page', $page);
    }

    public function history(string $organization, string $calculation, int $page): LengthAwarePaginator
    {
        return StatisticalCalculationRevision::query()->where('organization_id', $organization)->where('calculation_id', $calculation)
            ->orderByDesc('number')->paginate(10, ['*'], 'page', $page);
    }

    public function invalidate(string $organization, string $observation, string $version): int
    {
        return StatisticalCalculation::query()->where('organization_id', $organization)->whereIn('id',
            StatisticalCalculationSource::query()->select('calculation_id')->where('organization_id', $organization)->where('observation_id', $observation))
            ->update(['status' => 'stale', 'version' => $version, 'failure_code' => null]);
    }

    public function affected(string $organization, string $observation, ?string $after): array
    {
        return array_values(StatisticalCalculation::query()->where('organization_id', $organization)->where('status', 'stale')
            ->whereIn('id', StatisticalCalculationSource::query()->select('calculation_id')->where('organization_id', $organization)->where('observation_id', $observation))
            ->when($after !== null, fn (Builder $query) => $query->where('id', '>', $after))->orderBy('id')->limit(25)->get(['id', 'organization_id', 'actor_user_id', 'version'])->all());
    }
}
