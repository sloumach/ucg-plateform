<?php

namespace Database\Factories;

use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PersonDepartureItem> */
class PersonDepartureItemFactory extends Factory
{
    protected $model = PersonDepartureItem::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['person_id' => Person::factory(), 'organization_id' => fn (array $attributes) => Person::query()->whereKey($attributes['person_id'])->firstOrFail()->organization_id, 'kind' => 'equipment', 'title' => 'Clavier à restituer', 'notes' => null, 'status' => 'open', 'due_at' => null, 'completed_at' => null, 'version' => (string) Str::uuid()];
    }
}
