<?php

namespace Database\Factories;

use App\Modules\Academy\Domain\Models\Cohort;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Cohort> */
final class CohortFactory extends Factory
{
    protected $model = Cohort::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'name' => fake()->words(3, true), 'status' => 'active', 'version' => (string) Str::uuid()];
    }
}
