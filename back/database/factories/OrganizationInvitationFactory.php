<?php

namespace Database\Factories;

use App\Modules\Tenancy\Domain\InvitationStatus;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationInvitation> */
class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'email' => fake()->safeEmail(),
            'roles' => ['member'], 'status' => InvitationStatus::Pending,
            'invited_by' => fn (array $attributes): int => Organization::query()->whereKey($attributes['organization_id'])->firstOrFail()->owner_user_id,
            'starts_at' => now(), 'ends_at' => null, 'expires_at' => now()->addDays(7)];
    }
}
