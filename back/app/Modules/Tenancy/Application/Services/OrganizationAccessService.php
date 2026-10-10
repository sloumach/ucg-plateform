<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\MembershipRole;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class OrganizationAccessService
{
    public function __construct(private OrganizationRepository $organizations, private MembershipRepository $memberships) {}

    public function find(string $id, int $actorUserId): Organization
    {
        return $this->organizations->findAccessible($id, $actorUserId);
    }

    /** @return list<string> */
    public function roles(Organization $organization, int $actorUserId): array
    {
        if ($organization->owner_user_id === $actorUserId) {
            return ['owner'];
        }
        $membership = $this->effectiveMembership($organization, $actorUserId);

        return $membership->roles ?? [];
    }

    public function effectiveMembership(Organization $organization, int $actorUserId): ?OrganizationMembership
    {
        if ($organization->owner_user_id === $actorUserId) {
            return null;
        }
        $membership = $this->memberships->forAccount($organization->id, $actorUserId);

        return $membership !== null && $membership->isEffective(now()) ? $membership : null;
    }

    public function canAdminister(Organization $organization, int $actorUserId): bool
    {
        return $organization->owner_user_id === $actorUserId
            || in_array(MembershipRole::Administrator->value, $this->roles($organization, $actorUserId), true);
    }

    /** Called after locking the organization, as are all membership mutations. */
    public function requireAdministration(Organization $organization, int $actorUserId): void
    {
        if (! $this->canAdminister($organization, $actorUserId)) {
            throw new AuthorizationException;
        }
    }

    /** Administrators may manage ordinary members, but only the owner delegates administration. */
    /** @param list<string> $roles */
    public function requireRoleDelegation(Organization $organization, int $actorUserId, array $roles): void
    {
        $this->requireAdministration($organization, $actorUserId);
        if ($organization->owner_user_id !== $actorUserId && in_array(MembershipRole::Administrator->value, $roles, true)) {
            throw new AuthorizationException;
        }
    }
}
