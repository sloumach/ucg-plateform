<?php

namespace App\Modules\Academy\Infrastructure\Persistence;

use App\Exceptions\DomainConflictException;
use App\Modules\Academy\Domain\Contracts\AcademyCoachingRepository;
use App\Modules\Academy\Domain\Models\AcademyCoachingRevision;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\AcademyFeedback;
use App\Modules\Academy\Domain\Models\AcademyObjective;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;

final readonly class EloquentAcademyCoachingRepository implements AcademyCoachingRepository
{
    /** @return Builder<AcademyObjective> */
    private function scoped(string $org, ?string $cycle, ?string $person, bool $self): Builder
    {
        $enrollments = $self && $person !== null ? $this->selfEnrollmentIds($org, $person) : [];

        return AcademyObjective::query()->where('organization_id', $org)->when($cycle !== null, fn ($q) => $q->where('cycle_id', $cycle))
            ->when($self, function (Builder $q) use ($org, $enrollments): void {
                // Correlate tenant, cycle and individual audience before pagination or decryption.
                $q->whereNotNull('id')->whereExists(fn (Query $e) => $e->selectRaw('1')->from('academy_enrollments as viewer')
                    ->where('viewer.organization_id', $org)->whereIn('viewer.id', $enrollments)->where('viewer.status', 'active')->where('viewer.role', 'student')
                    ->whereColumn('viewer.cycle_id', 'academy_objectives.cycle_id')
                    ->where(function (Query $audience): void {
                        $audience->whereNull('academy_objectives.target_enrollment_id')->orWhereColumn('viewer.id', 'academy_objectives.target_enrollment_id');
                    }));
            });
    }

    /** @return list<string> */
    private function selfEnrollmentIds(string $org, string $person): array
    {
        // UTC bounds are conservative; the final comparison uses each cycle's IANA timezone.
        $rows = AcademyEnrollment::query()->join('academy_cycles as cycle', fn ($j) => $j
            ->on('cycle.id', '=', 'academy_enrollments.cycle_id')->on('cycle.organization_id', '=', 'academy_enrollments.organization_id'))
            ->where('academy_enrollments.organization_id', $org)->where('person_id', $person)->where('role', 'student')
            ->where('academy_enrollments.status', 'active')->where('academy_enrollments.starts_on', '<=', now('UTC')->addDay()->toDateString())
            ->where('academy_enrollments.ends_on', '>=', now('UTC')->subDay()->toDateString())->orderBy('academy_enrollments.id')->limit(101)
            ->get(['academy_enrollments.id', 'academy_enrollments.starts_on', 'academy_enrollments.ends_on', 'cycle.timezone as cycle_timezone']);
        if ($rows->count() > 100) {
            throw new DomainConflictException(__('coaching.too_many_cycles'), 'ACADEMY_SELF_CYCLE_LIMIT');
        }
        $ids = [];
        foreach ($rows as $row) {
            $today = now((string) $row->getAttribute('cycle_timezone'))->toDateString();
            if ($row->starts_on <= $today && $row->ends_on >= $today) {
                $ids[] = $row->id;
            }
        }

        return $ids;
    }

    public function objectives(string $org, ?string $cycle, array $filters, ?string $person = null, bool $self = false): LengthAwarePaginator
    {
        return $this->scoped($org, $cycle, $person, $self)->with($self ? [] : ['responsible', 'target'])
            ->orderBy('due_on')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function objective(string $org, string $id, ?string $cycle, bool $lock = false, ?string $person = null, bool $self = false): AcademyObjective
    {
        return $this->scoped($org, $cycle, $person, $self)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail(strtolower($id));
    }

    public function feedback(string $org, string $objective, string $audience, array $filters, bool $self = false): LengthAwarePaginator
    {
        return AcademyFeedback::query()->where('organization_id', $org)->where('objective_id', $objective)->where('audience', $audience)
            ->when($self, fn ($q) => $q->where('status', 'active'))->orderByDesc('created_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function feedbackRecord(string $org, string $objective, string $id, string $audience): AcademyFeedback
    {
        return AcademyFeedback::query()->where('organization_id', $org)->where('objective_id', $objective)->where('audience', $audience)->lockForUpdate()->findOrFail(strtolower($id));
    }

    public function history(string $org, string $objective, array $filters, bool $internal): LengthAwarePaginator
    {
        return AcademyCoachingRevision::query()->where('organization_id', $org)->where('objective_id', $objective)
            ->when(! $internal, fn ($q) => $q->where('audience', '!=', 'internal'))->orderByDesc('recorded_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function save(AcademyObjective|AcademyFeedback $record, array $attributes): AcademyObjective|AcademyFeedback
    {
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function revise(array $attributes): void
    {
        (new AcademyCoachingRevision)->forceFill($attributes)->save();
    }
}
