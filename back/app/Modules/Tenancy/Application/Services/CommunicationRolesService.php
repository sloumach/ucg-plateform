<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Tenancy\Application\Contracts\CommunicationRolesDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Contracts\CommunicationRolesRepository;
use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class CommunicationRolesService implements CommunicationRolesDirectory
{
    public function __construct(private CommunicationRolesRepository $records, private TenantAuthorization $authorization,
        private OrganizationRepository $organizations, private MembershipRepository $memberships) {}

    public function current(TenantContext $context): array
    {
        $this->authorization->authorize($context, 'communication.read');
        $org = $this->organizations->findAccessible($context->organizationId, $context->actorUserId);
        $membership = $org->owner_user_id === $context->actorUserId ? null : $this->memberships->forAccount($org->id, $context->actorUserId);

        return $this->records->current($org->id, $context->actorUserId, $membership?->id);
    }

    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorizeWrite($context);

        return $this->records->choices($context->organizationId, max(1, min(100000, $page)), max(1, min(100, $perPage)));
    }

    public function assertActive(TenantContext $context, string $id): void
    {
        $this->authorizeWrite($context);
        $this->records->assertActive($context->organizationId, $id);
    }

    private function authorizeWrite(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'communication.read');
        $this->authorization->authorize($context, 'communication.write');
    }
}
