<?php

namespace Database\Factories;

use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrganizationRoleAssignment> */
final class OrganizationRoleAssignmentFactory extends Factory
{
    protected $model = OrganizationRoleAssignment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['membership_id' => OrganizationMembership::factory(), 'organization_id' => fn (array $attributes) => OrganizationMembership::query()->where('id', $attributes['membership_id'])->firstOrFail()->organization_id,
            'user_id' => fn (array $attributes) => OrganizationMembership::query()->where('id', $attributes['membership_id'])->firstOrFail()->user_id,
            'role_id' => fn (array $attributes) => OrganizationRole::factory()->create(['organization_id' => $attributes['organization_id']])->id,
            'scope_type' => 'organization', 'scope_id' => fn (array $attributes) => $attributes['organization_id'],
            'status' => 'active', 'starts_at' => now()->subHour(), 'ends_at' => null, 'version' => (string) Str::uuid()];
    }
}
