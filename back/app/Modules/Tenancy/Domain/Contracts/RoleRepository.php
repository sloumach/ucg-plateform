<?php

namespace App\Modules\Tenancy\Domain\Contracts;

use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

interface RoleRepository
{
    /** Effective assignments only; the caller must validate the actor and source lifecycle. */
    public function scopeIds(string $org, int $userId, ?string $membershipId, string $type, string $permission): Builder;

    /** Permissions from bounded role definitions, without loading assignments or source collections.
     * @return list<string>
     */
    public function scopedPermissions(string $org, int $userId, ?string $membershipId, string $type, Builder $sources): array;

    /** @return LengthAwarePaginator<int, OrganizationRole> */
    public function roles(string $organizationId, int $perPage, int $page): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, OrganizationRoleAssignment> */
    public function assignments(string $organizationId, ?int $userId, int $perPage, int $page): LengthAwarePaginator;

    public function role(string $organizationId, string $id, bool $lock = false): OrganizationRole;

    public function assignment(string $organizationId, string $id, bool $lock = false): OrganizationRoleAssignment;

    public function roleCount(string $organizationId): int;

    public function assignedTo(string $organizationId, string $roleId, int $userId): bool;

    /** @param array<string, mixed> $attributes */
    public function saveRole(?OrganizationRole $role, array $attributes): OrganizationRole;

    /** @param array<string, mixed> $attributes */
    public function saveAssignment(?OrganizationRoleAssignment $assignment, array $attributes): OrganizationRoleAssignment;

    public function hasGrant(string $organizationId, int $userId, ?string $membershipId, string $permission,
        string $scopeType, string $scopeId, CarbonInterface $start, ?CarbonInterface $end, bool $coverPeriod = false, bool $organizationCovers = true): bool;

    /** @return list<string> */
    public function grantedPermissions(string $organizationId, int $userId, ?string $membershipId): array;

    public function hasPrivilegedGrant(int $userId): bool;

    /** @param list<string> $eligibleIds */
    public function hasDatedScopeGrant(string $org, int $userId, ?string $membershipId, string $permission, string $type, string $id, array $eligibleIds): bool;

    /** @return iterable<OrganizationRoleAssignment> */
    public function liveAssignmentsForMembership(string $organizationId, string $membershipId): iterable;
}
