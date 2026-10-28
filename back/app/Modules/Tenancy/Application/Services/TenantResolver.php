<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Contracts\ApprovedTenantDomains;
use App\Modules\Tenancy\Domain\Exceptions\TenantContextRequiredException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final readonly class TenantResolver
{
    public function __construct(
        private OrganizationAccessService $access,
        private ApprovedTenantDomains $domains,
        private TenantAuthorization $authorization,
        private ErasureAccessGate $erasureGate,
    ) {}

    public function resolve(?string $routeOrganizationId, string $host, int $actorUserId, string $requestId): TenantContext
    {
        $domainOrganizationId = $this->domains->organizationIdFor($host);
        if ($routeOrganizationId !== null && ! Str::isUuid($routeOrganizationId)) {
            throw new ModelNotFoundException;
        }
        $routeOrganizationId = $routeOrganizationId === null ? null : strtolower($routeOrganizationId);
        if ($routeOrganizationId !== null && $domainOrganizationId !== null && $routeOrganizationId !== $domainOrganizationId) {
            throw new ModelNotFoundException;
        }
        $organizationId = $routeOrganizationId ?? $domainOrganizationId;
        if ($organizationId === null) {
            throw new TenantContextRequiredException;
        }

        $organization = $this->access->find($organizationId, $actorUserId);
        $this->erasureGate->assertReadable($organizationId, $organization->retention_version);
        if (! $organization->acceptsBusinessOperations()) {
            throw new DomainConflictException(__('tenancy.errors.inactive'), 'ORGANIZATION_INACTIVE');
        }

        $isOwner = $organization->owner_user_id === $actorUserId;
        $membership = $this->access->effectiveMembership($organization, $actorUserId);
        if (! $isOwner && $membership === null) {
            throw new ModelNotFoundException;
        }

        $context = new TenantContext(
            $organization->id, $actorUserId, $organization->slug,
            $organization->timezone, $organization->language, $requestId,
            $isOwner ? ['owner'] : ($membership->roles ?? []),
            $isOwner, $organization->name, $membership?->id,
        );

        return new TenantContext(
            $context->organizationId, $context->actorUserId, $context->slug, $context->timezone,
            $context->language, $context->requestId, $context->roles, $context->isOwner,
            $context->organizationName, $context->membershipId, $this->authorization->permissions($context),
        );
    }
}
