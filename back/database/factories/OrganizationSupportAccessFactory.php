<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationSupportAccess;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrganizationSupportAccess> */
final class OrganizationSupportAccessFactory extends Factory
{
    protected $model = OrganizationSupportAccess::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'actor_user_id' => User::factory()->withMfa(),
            'platform_role_version' => (string) Str::uuid(),
            'reason' => 'Diagnostic incident de connexion', 'actions' => ['organization.inspect'],
            'status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addMinutes(15)];
    }
}
