<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Domain\Contracts\RoleRepository;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentRoleRepository implements RoleRepository
{
    public function roles(string $organizationId, int $perPage, int $page): LengthAwarePaginator
    {
        return OrganizationRole::query()->where('organization_id', $organizationId)->orderBy('name')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function assignments(string $organizationId, ?int $userId, int $perPage, int $page): LengthAwarePaginator
    {
        return OrganizationRoleAssignment::query()->where('organization_id', $organizationId)->when($userId !== null, fn (Builder $query) => $query->where('user_id', $userId))
            ->with('role')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function role(string $organizationId, string $id, bool $lock = false): OrganizationRole
    {
        return OrganizationRole::query()->where('organization_id', $organizationId)->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function assignment(string $organizationId, string $id, bool $lock = false): OrganizationRoleAssignment
    {
        return OrganizationRoleAssignment::query()->where('organization_id', $organizationId)->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function roleCount(string $organizationId): int
    {
        return OrganizationRole::query()->where('organization_id', $organizationId)->count();
    }

    public function assignedTo(string $organizationId, string $roleId, int $userId): bool
    {
        return OrganizationRoleAssignment::query()->where('organization_id', $organizationId)->where('role_id', $roleId)->where('user_id', $userId)->where('status', '!=', 'revoked')->exists();
    }

    public function saveRole(?OrganizationRole $role, array $attributes): OrganizationRole
    {
        $role ??= new OrganizationRole;
        $role->forceFill($attributes)->save();

        return $role;
    }

    public function saveAssignment(?OrganizationRoleAssignment $assignment, array $attributes): OrganizationRoleAssignment
    {
        $assignment ??= new OrganizationRoleAssignment;
        $assignment->forceFill($attributes)->save();

        return $assignment;
    }

    public function hasGrant(string $organizationId, int $userId, ?string $membershipId, string $permission,
        string $scopeType, string $scopeId, CarbonInterface $start, ?CarbonInterface $end, bool $coverPeriod = false): bool
    {
        return $this->grants($organizationId, $userId, $membershipId, $start)
            ->whereHas('role', fn (Builder $role) => $role->where('active', true)->whereJsonContains('permissions', $permission))
            ->where(function (Builder $scope) use ($organizationId, $scopeType, $scopeId): void {
                $scope->where(fn (Builder $query) => $query->where('scope_type', 'organization')->where('scope_id', $organizationId))
                    ->orWhere(fn (Builder $query) => $query->where('scope_type', $scopeType)->where('scope_id', $scopeId));
            })
            ->when($coverPeriod, function (Builder $query) use ($end): void {
                if ($end === null) {
                    $query->whereNull('ends_at');
                } else {
                    $query->where(fn (Builder $period) => $period->whereNull('ends_at')->orWhere('ends_at', '>=', $end));
                }
            })->exists();
    }

    public function grantedPermissions(string $organizationId, int $userId, ?string $membershipId): array
    {
        // Role count is bounded per tenant; joining only effective assignments avoids loading members.
        $roles = OrganizationRole::query()->where('organization_id', $organizationId)->where('active', true)
            ->whereIn('id', $this->grants($organizationId, $userId, $membershipId, now())->where('scope_type', 'organization')->where('scope_id', $organizationId)->select('role_id'))
            ->get(['permissions']);

        return array_values(array_unique($roles->flatMap(fn (OrganizationRole $role): array => $role->permissions)->all()));
    }

    public function hasPrivilegedGrant(int $userId): bool
    {
        return OrganizationRoleAssignment::query()->where('user_id', $userId)->where('status', 'active')->where('starts_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->whereHas('role', fn (Builder $query) => $query->where('active', true)->where('privileged', true))
            ->where(function (Builder $query) use ($userId): void {
                $query->whereExists(function (\Illuminate\Database\Query\Builder $membership) use ($userId): void {
                    $membership->selectRaw('1')->from('organization_memberships')
                        ->whereColumn('organization_memberships.id', 'organization_role_assignments.membership_id')
                        ->whereColumn('organization_memberships.organization_id', 'organization_role_assignments.organization_id')
                        ->where('organization_memberships.user_id', $userId)->where('organization_memberships.status', 'active')
                        ->where('organization_memberships.starts_at', '<=', now())
                        ->where(fn ($period) => $period->whereNull('organization_memberships.ends_at')->orWhere('organization_memberships.ends_at', '>', now()));
                })->orWhere(function (Builder $owner) use ($userId): void {
                    $owner->whereNull('membership_id')->whereExists(function (\Illuminate\Database\Query\Builder $organization) use ($userId): void {
                        $organization->selectRaw('1')->from('organizations')->whereColumn('organizations.id', 'organization_role_assignments.organization_id')->where('owner_user_id', $userId);
                    });
                });
            })->exists();
    }

    public function hasDatedScopeGrant(string $org, int $userId, ?string $membershipId, string $permission, string $type, string $id, array $eligibleIds): bool
    {
        return $this->grants($org, $userId, $membershipId, now())->where('scope_type', $type)->where('scope_id', $id)->whereIn('id', $eligibleIds)
            ->whereHas('role', fn (Builder $role) => $role->where('active', true)->whereJsonContains('permissions', $permission))->exists();
    }

    public function liveAssignmentsForMembership(string $organizationId, string $membershipId): iterable
    {
        return OrganizationRoleAssignment::query()->where('organization_id', $organizationId)->where('membership_id', $membershipId)->where('status', '!=', 'revoked')->lazyById(100);
    }

    /** @return Builder<OrganizationRoleAssignment> */
    private function grants(string $organizationId, int $userId, ?string $membershipId, CarbonInterface $at): Builder
    {
        return OrganizationRoleAssignment::query()->where('organization_id', $organizationId)->where('user_id', $userId)->where('membership_id', $membershipId)
            ->where('status', 'active')->where('starts_at', '<=', $at)->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }
}
