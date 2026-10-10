<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationSetting;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => 'org-'.fake()->unique()->uuid(),
            'owner_user_id' => User::factory(),
            'status' => OrganizationStatus::Active,
            'timezone' => 'UTC',
            'language' => 'fr',
            'country' => 'FR',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization): void {
            $settings = new OrganizationSetting;
            $settings->organization_id = $organization->id;
            $settings->week_starts_on = 1;
            $settings->date_format = 'd/m/Y';
            $settings->save();
        });
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => OrganizationStatus::Suspended]);
    }
}
