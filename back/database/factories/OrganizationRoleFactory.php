<?php

namespace Database\Factories;

use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrganizationRole> */
final class OrganizationRoleFactory extends Factory
{
    protected $model = OrganizationRole::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'key' => 'role-'.fake()->unique()->lexify('????????'),
            'name' => 'Coach', 'permissions' => ['sports.read'], 'active' => true, 'privileged' => false, 'version' => (string) Str::uuid()];
    }

    public function privileged(): static
    {
        return $this->state(fn (): array => ['permissions' => ['sports.write'], 'privileged' => true]);
    }
}
