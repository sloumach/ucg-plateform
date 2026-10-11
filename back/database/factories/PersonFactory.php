<?php

namespace Database\Factories;

use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\PersonStatus;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Person> */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'user_id' => null, 'pseudonym' => fake()->userName(),
            'language' => 'fr', 'timezone' => 'UTC', 'status' => PersonStatus::Active,
            'biography_public' => null, 'biography_internal' => null, 'first_name' => null, 'last_name' => null,
            'contact_email' => null, 'contact_phone' => null, 'version' => (string) Str::uuid()];
    }

    public function withAdministrativeIdentity(): static
    {
        return $this->state(fn (): array => ['first_name' => 'Camille', 'last_name' => 'Martin',
            'contact_email' => 'camille@example.test', 'contact_phone' => '+33123456789',
            'biography_internal' => 'Note interne confidentielle']);
    }
}
