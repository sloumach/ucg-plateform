<?php

namespace App\Modules\Performance\Infrastructure\Persistence;

use App\Modules\Performance\Domain\Contracts\ObservationRepository;
use App\Modules\Performance\Domain\Models\ObservationRevision;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as Query;

final readonly class EloquentObservationRepository implements ObservationRepository
{
    /** @return Builder<StatisticalObservation> */
    private function visible(string $org, Query $matches): Builder
    {
        return StatisticalObservation::query()->where('organization_id', $org)->where(fn (Builder $q) => $q->whereNull('match_id')->orWhereIn('match_id', $matches))->with(['current' => fn (HasOne $q) => $q->where('organization_id', $org)]);
    }

    public function paginate(string $org, Query $matches, array $filters): LengthAwarePaginator
    {
        $q = $this->visible($org, $matches)->whereHas('current', function (Builder $q) use ($filters, $org): void {
            $q->where('organization_id', $org);
            if (isset($filters['status'])) {
                $q->where('status', $filters['status']);
            }
        });
        foreach (['person_id', 'metric_id', 'source_kind'] as $f) {
            if (isset($filters[$f])) {
                $q->where($f, str_ends_with($f, '_id') ? strtolower($filters[$f]) : $filters[$f]);
            }
        }
        if (isset($filters['from'])) {
            $q->where('period_end', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $q->where('period_start', '<=', $filters['to']);
        }

        return $q->orderByDesc('period_start')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $org, string $id, Query $matches, bool $lock = false): StatisticalObservation
    {
        return $this->visible($org, $matches)->when($lock, fn (Builder $q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function create(array $attributes): StatisticalObservation
    {
        $r = new StatisticalObservation;
        $r->forceFill($attributes)->save();

        return $r;
    }

    public function append(array $attributes): ObservationRevision
    {
        $r = new ObservationRevision;
        $r->forceFill($attributes)->save();

        return $r;
    }

    public function history(string $org, string $id, int $page, int $size): LengthAwarePaginator
    {
        return ObservationRevision::query()->where('organization_id', $org)->where('observation_id', $id)->orderByDesc('number')->paginate($size, ['*'], 'page', $page);
    }

    public function version(string $org, string $id, string $version): ObservationRevision
    {
        return ObservationRevision::query()->where('organization_id', $org)->where('observation_id', $id)->findOrFail($version);
    }
}
