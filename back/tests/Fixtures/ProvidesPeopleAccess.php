<?php

namespace Tests\Fixtures;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Support\Str;

trait ProvidesPeopleAccess
{
    /** @param list<string> $permissions */
    protected function grantPeopleAccess(Organization $organization, User $user, array $permissions): OrganizationRoleAssignment
    {
        $membership = OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
        $role = OrganizationRole::factory()->create(['organization_id' => $organization->id, 'permissions' => $permissions,
            'privileged' => array_diff($permissions, ['sports.read']) !== []]);

        return OrganizationRoleAssignment::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id,
            'membership_id' => $membership->id, 'role_id' => $role->id, 'scope_id' => $organization->id]);
    }

    protected function personContext(Organization $organization, User $user): TenantContext
    {
        return new TenantContext($organization->id, $user->id, $organization->slug, 'UTC', 'fr', (string) Str::uuid());
    }

    /** @return array<string, mixed> */
    protected function personPayload(): array
    {
        return ['pseudonym' => 'Camille', 'language' => 'fr', 'timezone' => 'Europe/Paris', 'status' => 'active',
            'biography_public' => 'Joueuse de l’équipe'];
    }
}
