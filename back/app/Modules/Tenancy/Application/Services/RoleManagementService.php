<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Data\RoleAssignmentData;
use App\Modules\Tenancy\Application\Data\RoleData;
use App\Modules\Tenancy\Application\Validation\RoleInput;
use App\Modules\Tenancy\Domain\Contracts\AccessAuditRepository;
use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Contracts\RoleRepository;
use App\Modules\Tenancy\Domain\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Domain\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final readonly class RoleManagementService
{
    public function __construct(private RoleRepository $roles, private OrganizationRepository $organizations,
        private MembershipRepository $memberships, private AccountDirectory $accounts,
        private TenantAuthorization $authorization, private AuthorizationScopeDirectory $scopes,
        private AccessAuditRepository $audit, private ConnectionInterface $database, private Factory $validator) {}

    /** @return LengthAwarePaginator<int, OrganizationRole> */
    public function roles(TenantContext $context, int $perPage, int $page): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'roles.assign');
        $this->validatePage($perPage, $page);

        return $this->roles->roles($context->organizationId, $perPage, $page);
    }

    /** @return LengthAwarePaginator<int, OrganizationRoleAssignment> */
    public function assignments(TenantContext $context, int $perPage, int $page, bool $own = false): LengthAwarePaginator
    {
        $this->authorization->authorize($context, $own ? 'organization.view' : 'roles.assign');
        $this->validatePage($perPage, $page);

        return $this->roles->assignments($context->organizationId, $own ? $context->actorUserId : null, $perPage, $page);
    }

    public function saveRole(TenantContext $context, RoleData $data, ?string $id = null): OrganizationRole
    {
        $this->validator->make([...$data->attributes(), 'version' => $data->version],
            [...RoleInput::roleRules(), 'version' => $id === null ? ['nullable', 'uuid'] : ['required', 'uuid']], RoleInput::messages())->validate();
        try {
            return $this->database->transaction(function () use ($context, $data, $id): OrganizationRole {
                $this->organizations->lock($context->organizationId);
                $this->authorization->authorize($context, 'roles.manage');
                $role = $id === null ? null : $this->roles->role($context->organizationId, $id, true);
                if ($role !== null) {
                    $this->assertVersion($role->version, $data->version);
                    if ($role->key !== $data->key) {
                        throw new DomainConflictException(__('authorization.identity'), 'ROLE_IDENTITY_IMMUTABLE');
                    }
                    if ($this->roles->assignedTo($context->organizationId, $role->id, $context->actorUserId)
                        && (array_diff($data->permissions, $role->permissions) !== [] || ($data->active && ! $role->active))) {
                        throw new DomainConflictException(__('authorization.self'), 'ROLE_SELF_ESCALATION_FORBIDDEN');
                    }
                } elseif ($this->roles->roleCount($context->organizationId) >= RoleInput::MAX_ROLES) {
                    throw new DomainConflictException(__('authorization.limit'), 'ROLE_LIMIT_REACHED');
                }
                $before = $role?->attributesToArray();
                $result = $this->roles->saveRole($role, [...$data->attributes(), 'organization_id' => $context->organizationId,
                    'privileged' => collect($data->permissions)->contains(fn (string $permission): bool => Permission::from($permission)->privileged()),
                    'version' => (string) Str::uuid()]);
                $this->record($context, $role === null ? 'role.created' : 'role.updated', $result->id, $before, $result->attributesToArray());

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('authorization.duplicate'), 'ROLE_ALREADY_EXISTS');
        }
    }

    public function saveAssignment(TenantContext $context, RoleAssignmentData $data, ?string $id = null): OrganizationRoleAssignment
    {
        $this->validator->make([...$data->attributes(), 'version' => $data->version],
            [...RoleInput::assignmentRules(), 'version' => $id === null ? ['nullable', 'uuid'] : ['required', 'uuid']], RoleInput::messages())->validate();
        $start = CarbonImmutable::parse($data->startsAt)->utc();
        $end = $data->endsAt === null ? null : CarbonImmutable::parse($data->endsAt)->utc();
        try {
            return $this->database->transaction(function () use ($context, $data, $id, $start, $end): OrganizationRoleAssignment {
                if ($this->accounts->lockVerifiedEmail($data->userId) === null) {
                    throw new DomainConflictException(__('authorization.subject'), 'ROLE_SUBJECT_INVALID');
                }
                $organization = $this->organizations->lock($context->organizationId);
                $this->authorization->authorize($context, 'roles.assign');
                $assignment = $id === null ? null : $this->roles->assignment($context->organizationId, $id, true);
                if ($assignment !== null) {
                    $this->assertVersion($assignment->version, $data->version);
                    if ($assignment->role_id !== $data->roleId || $assignment->user_id !== $data->userId
                        || $assignment->scope_type !== $data->scopeType || $assignment->scope_id !== $data->scopeId) {
                        throw new DomainConflictException(__('authorization.identity'), 'ROLE_ASSIGNMENT_IDENTITY_IMMUTABLE');
                    }
                    if ($assignment->status === 'revoked') {
                        throw new DomainConflictException(__('authorization.terminal'), 'ROLE_ASSIGNMENT_REVOKED');
                    }
                }
                $own = $data->userId === $context->actorUserId;
                $onlyRestrict = $assignment !== null && in_array($data->status, ['revoked', 'suspended'], true)
                    && $assignment->starts_at->equalTo($start) && ($assignment->ends_at?->toIso8601String() === $end?->toIso8601String());
                if ($own && ! $onlyRestrict) {
                    throw new DomainConflictException(__('authorization.self'), 'ROLE_SELF_ESCALATION_FORBIDDEN');
                }
                $role = $this->roles->role($organization->id, $data->roleId, true);
                if (! $onlyRestrict) {
                    if (! $role->active) {
                        throw new DomainConflictException(__('authorization.inactive_role'), 'ROLE_INACTIVE');
                    }
                    if (! $this->scopes->exists($organization->id, $data->scopeType, $data->scopeId)) {
                        throw new DomainConflictException(__('authorization.scope_unavailable'), 'AUTHORIZATION_SCOPE_UNAVAILABLE');
                    }
                    if ($data->scopeType !== 'organization' && collect($role->permissions)->contains(fn (string $permission): bool => Permission::from($permission)->organizationOnly())) {
                        throw new DomainConflictException(__('authorization.scope_governance'), 'ROLE_SCOPE_INCOMPATIBLE');
                    }
                }
                $ownerTarget = $organization->owner_user_id === $data->userId;
                $membership = $ownerTarget ? null : $this->memberships->forAccount($organization->id, $data->userId, true);
                if (! $ownerTarget && ($membership === null || (! $onlyRestrict && ! $membership->isEffective(now())))) {
                    throw new DomainConflictException(__('authorization.subject'), 'ROLE_SUBJECT_INVALID');
                }
                if (! $onlyRestrict && $membership !== null && ($start->lessThan($membership->starts_at)
                    || ($membership->ends_at !== null && ($end === null || $end->greaterThan($membership->ends_at))))) {
                    throw new DomainConflictException(__('authorization.period'), 'ROLE_PERIOD_OUTSIDE_MEMBERSHIP');
                }
                if ($organization->owner_user_id !== $context->actorUserId && ! $own) {
                    $actorMembership = $this->memberships->forAccount($organization->id, $context->actorUserId);
                    if ($actorMembership === null || ! $actorMembership->isEffective(now()) || (! $onlyRestrict && ($start->lessThan($actorMembership->starts_at)
                        || ($actorMembership->ends_at !== null && ($end === null || $end->greaterThan($actorMembership->ends_at)))))) {
                        throw new DomainConflictException(__('authorization.delegation'), 'ROLE_DELEGATION_DENIED');
                    }
                    foreach (array_unique(['roles.assign', ...$role->permissions]) as $permission) {
                        $type = $permission === 'roles.assign' ? 'organization' : $data->scopeType;
                        $scope = $permission === 'roles.assign' ? $organization->id : $data->scopeId;
                        if ($onlyRestrict ? ! $this->authorization->allows($context, $permission, $type, $scope)
                            : ! $this->roles->hasGrant($organization->id, $context->actorUserId, $actorMembership->id, $permission,
                                $type, $scope, $start, $end, true)) {
                            throw new DomainConflictException(__('authorization.delegation'), 'ROLE_DELEGATION_DENIED');
                        }
                    }
                }
                $before = $assignment?->attributesToArray();
                $result = $this->roles->saveAssignment($assignment, [...$data->attributes(), 'organization_id' => $organization->id,
                    'membership_id' => $membership?->id, 'starts_at' => $start, 'ends_at' => $end, 'version' => (string) Str::uuid()]);
                $this->record($context, $assignment === null ? 'role_assignment.created' : 'role_assignment.updated', $result->id, $before, $result->attributesToArray());

                return $result->load('role');
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('authorization.duplicate'), 'ROLE_ASSIGNMENT_ALREADY_EXISTS');
        }
    }

    /** Called under the organization lock; a later re-admission must not resurrect old grants. */
    public function revokeMembership(TenantContext $context, string $membershipId): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new \LogicException('Membership grants must be revoked in the membership transaction.');
        }
        $membership = $this->memberships->find($context->organizationId, $membershipId);
        if ($membership->status !== MembershipStatus::Revoked) {
            throw new \LogicException('Membership must be revoked before its grants.');
        }
        if ($membership->user_id !== $context->actorUserId) {
            $this->authorization->authorize($context, 'memberships.manage');
        }
        foreach ($this->roles->liveAssignmentsForMembership($context->organizationId, $membershipId) as $assignment) {
            $before = $assignment->attributesToArray();
            $this->roles->saveAssignment($assignment, ['status' => 'revoked', 'version' => (string) Str::uuid()]);
            $this->record($context, 'role_assignment.membership_revoked', $assignment->id, $before, $assignment->attributesToArray());
        }
    }

    private function assertVersion(string $current, ?string $expected): void
    {
        if ($current !== $expected) {
            throw new DomainConflictException(__('authorization.stale'), 'AUTHORIZATION_VERSION_STALE');
        }
    }

    private function validatePage(int $perPage, int $page): void
    {
        $this->validator->make(['per_page' => $perPage, 'page' => $page], ['per_page' => ['integer', 'between:1,100'], 'page' => ['integer', 'between:1,100000']], RoleInput::messages())->validate();
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function record(TenantContext $context, string $action, string $id, ?array $before, array $after): void
    {
        $this->audit->record(['organization_id' => $context->organizationId, 'actor_user_id' => $context->actorUserId,
            'action' => $action, 'subject_id' => $id, 'before' => $before, 'after' => $after, 'request_id' => $context->requestId, 'occurred_at' => now()]);
    }
}
