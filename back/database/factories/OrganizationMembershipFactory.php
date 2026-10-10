<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationMembership> */
class OrganizationMembershipFactory extends Factory
{
    protected $model = OrganizationMembership::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'user_id' => User::factory(),
            'roles' => ['member'], 'status' => MembershipStatus::Active, 'starts_at' => now()->subDay(), 'ends_at' => null];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => MembershipStatus::Suspended]);
    }

    public function administrator(): static
    {
        return $this->state(fn (): array => ['roles' => ['administrator']]);
    }
}
