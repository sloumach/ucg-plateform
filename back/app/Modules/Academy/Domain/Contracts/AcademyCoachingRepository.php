<?php

namespace App\Modules\Academy\Domain\Contracts;

use App\Modules\Academy\Domain\Models\AcademyCoachingRevision;
use App\Modules\Academy\Domain\Models\AcademyFeedback;
use App\Modules\Academy\Domain\Models\AcademyObjective;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AcademyCoachingRepository
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, AcademyObjective> */
    public function objectives(string $org, ?string $cycle, array $filters, ?string $person = null, bool $self = false): LengthAwarePaginator;

    public function objective(string $org, string $id, ?string $cycle, bool $lock = false, ?string $person = null, bool $self = false): AcademyObjective;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, AcademyFeedback> */
    public function feedback(string $org, string $objective, string $audience, array $filters, bool $self = false): LengthAwarePaginator;

    public function feedbackRecord(string $org, string $objective, string $id, string $audience): AcademyFeedback;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, AcademyCoachingRevision> */
    public function history(string $org, string $objective, array $filters, bool $internal): LengthAwarePaginator;

    /** @param array<string, mixed> $attributes */
    public function save(AcademyObjective|AcademyFeedback $record, array $attributes): AcademyObjective|AcademyFeedback;

    /** @param array<string, mixed> $attributes */
    public function revise(array $attributes): void;
}
