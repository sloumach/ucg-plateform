<?php

namespace App\Modules\Academy\Infrastructure\Persistence;

use App\Modules\Academy\Domain\Contracts\AcademyRepository;
use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\AcademyRevision;
use App\Modules\Academy\Domain\Models\Cohort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class EloquentAcademyRepository implements AcademyRepository
{
    public function paginate(string $organizationId, string $kind, array $filters, ?string $cohortId = null): LengthAwarePaginator
    {
        $query = match ($kind) {
            'cohort' => Cohort::query(), 'cycle' => AcademyCycle::query(),
            'enrollment' => AcademyEnrollment::query(), 'history' => AcademyRevision::query(),
            default => throw new \InvalidArgumentException('Unknown academy collection.'),
        };
        $query->where('organization_id', strtolower($organizationId));
        if ($cohortId !== null) {
            $query->where('cohort_id', strtolower($cohortId));
        }
        foreach (['status', 'cycle_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, strtolower($filters[$field]));
            }
        }
        if (isset($filters['q'])) {
            $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q']));
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$prefix.'%']);
        }

        return $query->orderByDesc($kind === 'history' ? 'recorded_at' : 'created_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function cohort(string $organizationId, string $id, bool $lock = false): Cohort
    {
        return Cohort::query()->where('organization_id', strtolower($organizationId))->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail(strtolower($id));
    }

    public function cycle(string $organizationId, string $cohortId, string $id, bool $lock = false): AcademyCycle
    {
        return AcademyCycle::query()->where('organization_id', strtolower($organizationId))->where('cohort_id', strtolower($cohortId))
            ->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail(strtolower($id));
    }

    public function enrollment(string $organizationId, string $cohortId, string $id, bool $lock = false): AcademyEnrollment
    {
        return AcademyEnrollment::query()->where('organization_id', strtolower($organizationId))->where('cohort_id', strtolower($cohortId))
            ->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail(strtolower($id));
    }

    public function save(Cohort|AcademyCycle|AcademyEnrollment $record, array $attributes): Cohort|AcademyCycle|AcademyEnrollment
    {
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function revise(array $attributes): void
    {
        (new AcademyRevision)->forceFill($attributes)->save();
    }

    public function overlaps(string $organizationId, string $cycleId, string $personId, string $startsOn, string $endsOn, ?string $exceptId): bool
    {
        return AcademyEnrollment::query()->where('organization_id', $organizationId)->where('cycle_id', $cycleId)
            ->where('person_id', $personId)->where('status', 'active')->where('starts_on', '<=', $endsOn)->where('ends_on', '>=', $startsOn)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }

    public function hasEnrollments(string $organizationId, string $cycleId): bool
    {
        return AcademyEnrollment::query()->where('organization_id', $organizationId)->where('cycle_id', $cycleId)->exists();
    }
}
