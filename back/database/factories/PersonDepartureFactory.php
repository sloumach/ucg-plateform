<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonDeparture;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PersonDeparture> */
class PersonDepartureFactory extends Factory
{
    protected $model = PersonDeparture::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['person_id' => Person::factory(), 'organization_id' => fn (array $attributes) => Person::query()->whereKey($attributes['person_id'])->firstOrFail()->organization_id, 'user_id' => null, 'actor_user_id' => User::factory(), 'reason_key' => 'other', 'departed_at' => now(), 'previous_version' => (string) Str::uuid(), 'person_version' => (string) Str::uuid(), 'pending_items_count' => 0];
    }
}
