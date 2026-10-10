<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Contracts\ApprovedTenantDomains;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Exceptions\TenantContextRequiredException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final readonly class TenantResolver
{
    public function __construct(
        private OrganizationRepository $organizations,
        private ApprovedTenantDomains $domains,
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

        // TEN-003 will extend access to active memberships; for now only the owner is authorized.
        $organization = $this->organizations->findOwned($organizationId, $actorUserId);
        if (! $organization->acceptsBusinessOperations()) {
            throw new DomainConflictException(__('tenancy.errors.inactive'), 'ORGANIZATION_INACTIVE');
        }

        return new TenantContext(
            $organization->id, $actorUserId, $organization->slug,
            $organization->timezone, $organization->language, $requestId,
        );
    }
}
