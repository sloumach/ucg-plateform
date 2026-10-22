<?php

namespace Database\Factories;

use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\Cohort;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AcademyCycle> */
final class AcademyCycleFactory extends Factory
{
    protected $model = AcademyCycle::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['cohort_id' => Cohort::factory(), 'organization_id' => fn (array $attributes): string => Cohort::query()->whereKey($attributes['cohort_id'])->firstOrFail()->organization_id,
            'name' => 'Cycle pilote', 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31', 'timezone' => 'Europe/Paris',
            'timetable' => [], 'status' => 'active', 'version' => (string) Str::uuid()];
    }
}
