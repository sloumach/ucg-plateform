<?php

namespace Database\Factories;

use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\People\Domain\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AcademyEnrollment> */
final class AcademyEnrollmentFactory extends Factory
{
    protected $model = AcademyEnrollment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['cycle_id' => AcademyCycle::factory(),
            'organization_id' => fn (array $attributes): string => AcademyCycle::query()->whereKey($attributes['cycle_id'])->firstOrFail()->organization_id,
            'cohort_id' => fn (array $attributes): string => AcademyCycle::query()->whereKey($attributes['cycle_id'])->firstOrFail()->cohort_id,
            'person_id' => fn (array $attributes): string => Person::factory()->create(['organization_id' => $attributes['organization_id'], 'status' => 'active'])->id,
            'role' => 'student', 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31', 'status' => 'active', 'version' => (string) Str::uuid()];
    }
}
