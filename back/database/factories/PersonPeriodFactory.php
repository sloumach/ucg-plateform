<?php

namespace Database\Factories;

use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PersonPeriod> */
class PersonPeriodFactory extends Factory
{
    protected $model = PersonPeriod::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['person_id' => Person::factory(), 'organization_id' => fn (array $attributes) => Person::query()->whereKey($attributes['person_id'])->firstOrFail()->organization_id, 'kind' => 'assignment', 'role_key' => 'player', 'starts_at' => now()->subDay(), 'ends_at' => null, 'status' => 'active', 'version' => (string) Str::uuid()];
    }
}
