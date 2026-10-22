<?php

namespace App\Modules\Academy\Domain\Contracts;

use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\AcademyRevision;
use App\Modules\Academy\Domain\Models\Cohort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AcademyRepository
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Cohort>|LengthAwarePaginator<int, AcademyCycle>|LengthAwarePaginator<int, AcademyEnrollment>|LengthAwarePaginator<int, AcademyRevision> */
    public function paginate(string $organizationId, string $kind, array $filters, ?string $cohortId = null): LengthAwarePaginator;

    public function cohort(string $organizationId, string $id, bool $lock = false): Cohort;

    public function cycle(string $organizationId, string $cohortId, string $id, bool $lock = false): AcademyCycle;

    public function enrollment(string $organizationId, string $cohortId, string $id, bool $lock = false): AcademyEnrollment;

    /** @param array<string, mixed> $attributes */
    public function save(Cohort|AcademyCycle|AcademyEnrollment $record, array $attributes): Cohort|AcademyCycle|AcademyEnrollment;

    /** @param array<string, mixed> $attributes */
    public function revise(array $attributes): void;

    public function overlaps(string $organizationId, string $cycleId, string $personId, string $startsOn, string $endsOn, ?string $exceptId): bool;

    public function hasEnrollments(string $organizationId, string $cycleId): bool;
}
