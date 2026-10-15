<?php

namespace Database\Factories;

use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Sponsor> */
class SponsorFactory extends Factory
{
    protected $model = Sponsor::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'name' => fake()->company(), 'industry' => null,
            'status' => 'prospect', 'version' => (string) Str::uuid()];
    }
}
