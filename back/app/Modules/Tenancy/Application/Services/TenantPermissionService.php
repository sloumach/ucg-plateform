<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeDirectory;
use App\Modules\Tenancy\Application\Contracts\DatedAuthorizationScopeDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Contracts\RoleRepository;
use App\Modules\Tenancy\Domain\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class TenantPermissionService implements TenantAuthorization
{
    public function __construct(private OrganizationRepository $organizations, private MembershipRepository $memberships,
        private RoleRepository $roles, private AuthorizationScopeDirectory $scopes, private AccountDirectory $accounts,
        private PlatformAccountDirectory $platform, private ErasureAccessGate $erasureGate) {}

    public function allows(TenantContext $context, string $permission, string $scopeType = 'organization', ?string $scopeId = null): bool
    {
        $action = Permission::tryFrom($permission);
        $scopeId ??= $context->organizationId;
        if ($action === null || ($action->organizationOnly() && $scopeType !== 'organization') || ! $this->scopes->exists($context->organizationId, $scopeType, $scopeId)) {
            return false;
        }
        if ((! $action->organizationOnly() || $action === Permission::AuditView) && $this->platform->isTechnical($context->actorUserId)) {
            return false;
        }
        try {
            $organization = $this->organizations->findAccessible($context->organizationId, $context->actorUserId);
        } catch (ModelNotFoundException) {
            return false;
        }
        $this->erasureGate->assertReadable($context->organizationId, $organization->retention_version);
        if (! $organization->acceptsBusinessOperations()) {
            return false;
        }
        $owner = $organization->owner_user_id === $context->actorUserId;
        $membership = $owner ? null : $this->memberships->forAccount($organization->id, $context->actorUserId);
        if (! $owner && ($membership === null || ! $membership->isEffective(now()))) {
            return false;
        }
        if ($action->privileged() && ! $this->accounts->hasConfirmedMfa($context->actorUserId)) {
            return false;
        }
        $governance = $owner || in_array('administrator', $membership->roles, true);
        if ($scopeType === 'organization' && $governance && in_array($permission, Permission::governance($owner), true)) {
            return true;
        }
        if ($permission === 'organization.view') {
            return true;
        }

        if ($scopeType === 'team' && $this->scopes instanceof DatedAuthorizationScopeDirectory) {
            if ($this->roles->hasGrant($organization->id, $context->actorUserId, $membership?->id, $permission, 'organization', $organization->id, now(), null)) {
                return true;
            }
            $ids = $this->scopes->assignmentIds($organization->id, 'team', $scopeId, $context->actorUserId, CarbonImmutable::now());

            return $ids !== [] && $this->roles->hasDatedScopeGrant($organization->id, $context->actorUserId, $membership?->id, $permission, 'team', $scopeId, $ids);
        }

        return $this->roles->hasGrant($organization->id, $context->actorUserId, $membership?->id, $permission, $scopeType, $scopeId, now(), null);
    }

    public function authorize(TenantContext $context, string $permission, string $scopeType = 'organization', ?string $scopeId = null): void
    {
        if (! $this->allows($context, $permission, $scopeType, $scopeId)) {
            throw new AuthorizationException;
        }
    }

    /** @return list<string> */
    public function permissions(TenantContext $context): array
    {
        $organization = $this->organizations->findAccessible($context->organizationId, $context->actorUserId);
        $this->erasureGate->assertReadable($context->organizationId, $organization->retention_version);
        if (! $organization->acceptsBusinessOperations()) {
            return [];
        }
        $owner = $organization->owner_user_id === $context->actorUserId;
        $membership = $owner ? null : $this->memberships->forAccount($organization->id, $context->actorUserId);
        if (! $owner && ($membership === null || ! $membership->isEffective(now()))) {
            return [];
        }
        $base = $owner || in_array('administrator', $membership->roles, true) ? Permission::governance($owner) : ['organization.view'];
        $permissions = array_values(array_unique([...$base, ...$this->roles->grantedPermissions($organization->id, $context->actorUserId, $membership?->id)]));
        $mfa = $this->accounts->hasConfirmedMfa($context->actorUserId);
        $technical = $this->platform->isTechnical($context->actorUserId);

        return array_values(array_filter($permissions, fn (string $permission): bool => ($action = Permission::tryFrom($permission)) !== null
            && (! $action->privileged() || $mfa) && (! $technical || ($action->organizationOnly() && $action !== Permission::AuditView))));
    }
}
