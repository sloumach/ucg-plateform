<?php

namespace Database\Factories;

use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\AcademyObjective;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AcademyObjective> */
class AcademyObjectiveFactory extends Factory
{
    protected $model = AcademyObjective::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['responsible_enrollment_id' => AcademyEnrollment::factory()->state(['role' => 'mentor']),
            'organization_id' => fn (array $a): string => AcademyEnrollment::query()->whereKey($a['responsible_enrollment_id'])->firstOrFail()->organization_id,
            'cohort_id' => fn (array $a): string => AcademyEnrollment::query()->whereKey($a['responsible_enrollment_id'])->firstOrFail()->cohort_id,
            'cycle_id' => fn (array $a): string => AcademyEnrollment::query()->whereKey($a['responsible_enrollment_id'])->firstOrFail()->cycle_id,
            'target_enrollment_id' => null, 'title' => 'Objectif collectif', 'description' => 'Travail tactique partagé',
            'due_on' => '2026-11-01', 'status' => 'active', 'version' => (string) Str::uuid()];
    }
}
