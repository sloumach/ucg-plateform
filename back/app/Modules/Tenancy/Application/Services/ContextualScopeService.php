<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeSource;
use App\Modules\Tenancy\Application\Contracts\ContextualAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Contracts\RoleRepository;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

final readonly class ContextualScopeService implements ContextualAuthorization
{
    public const READ = ['team' => 'sports.read', 'game' => 'sports.read', 'cohort' => 'academy.read', 'event' => 'calendar.read'];

    public function __construct(private AuthorizationScopeSource $sources, private TenantAuthorization $authorization,
        private RoleRepository $roles, private OrganizationRepository $organizations, private MembershipRepository $memberships,
        private AccountDirectory $accounts, private PlatformAccountDirectory $platform) {}

    public function ids(TenantContext $context, string $type, string $permission, bool $scopedOnly = false): ?Builder
    {
        $source = $this->sources->sources($context->organizationId, $type);
        $column = $type === 'game' ? 'source.id' : 'id';
        $source->select($column);
        $action = Permission::tryFrom($permission);
        if ($action === null || ! $action->supportsScope($type)) {
            return $source->whereRaw('1 = 0');
        }
        if (! $scopedOnly && $this->authorization->allows($context, $permission)) {
            return null;
        }
        if (! $this->authorization->allows($context, 'organization.view') || $this->platform->isTechnical($context->actorUserId)
            || ($action->privileged() && ! $this->accounts->hasConfirmedMfa($context->actorUserId))) {
            return $source->whereRaw('1 = 0');
        }
        $organization = $this->organizations->findAccessible($context->organizationId, $context->actorUserId);
        $membership = $organization->owner_user_id === $context->actorUserId ? null : $this->memberships->forAccount($organization->id, $context->actorUserId);

        return $source->whereIn($column, $this->roles->scopeIds($organization->id, $context->actorUserId, $membership?->id, $type, $permission, $this->sources->eligibleAssignmentIds($organization->id, $type, $context->actorUserId)));
    }

    public function any(TenantContext $context, string $type, string $permission): bool
    {
        $ids = $this->ids($context, $type, $permission);

        return $ids === null || $ids->exists();
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function choices(TenantContext $context, string $type, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'roles.assign');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw ValidationException::withMessages(['per_page' => __('validation.between.numeric', ['attribute' => 'per_page', 'min' => 1, 'max' => 100])]);
        }
        if (! isset(self::READ[$type]) || ! $this->any($context, $type, self::READ[$type])) {
            throw new AuthorizationException;
        }

        return $this->sources->choices($context, $type, $page, $perPage, $this->ids($context, $type, self::READ[$type]));
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function myTeams(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'organization.view');

        return $this->sources->choices($context, 'team', $page, $perPage, $this->ids($context, 'team', 'sports.read', true));
    }

    /** @return list<string> Display hints only, without changing the organization snapshot. */
    public function capabilities(TenantContext $context): array
    {
        $this->authorization->authorize($context, 'organization.view');
        if ($this->platform->isTechnical($context->actorUserId)) {
            return [];
        }
        $organization = $this->organizations->findAccessible($context->organizationId, $context->actorUserId);
        $membership = $organization->owner_user_id === $context->actorUserId ? null : $this->memberships->forAccount($organization->id, $context->actorUserId);
        $global = $this->authorization->permissions($context);
        $mfa = $this->accounts->hasConfirmedMfa($context->actorUserId);
        $result = [];
        foreach (self::READ as $type => $read) {
            if ($type === 'team') {
                continue;
            }
            $sources = $this->sources->sources($organization->id, $type)->select($type === 'game' ? 'source.id' : 'id');
            $permissions = $this->roles->scopedPermissions($organization->id, $context->actorUserId, $membership?->id, $type, $sources);
            if (! in_array($read, [...$global, ...$permissions], true)) {
                continue;
            }
            foreach ($permissions as $key) {
                $permission = Permission::tryFrom($key);
                if ($permission !== null && $permission->supportsScope($type) && ($mfa || ! $permission->privileged()) && ! in_array($key, $global, true)) {
                    $result[] = $key;
                }
            }
        }

        return array_values(array_unique($result));
    }
}
