<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\Teams\Application\Contracts\CommunicationTeamsDirectory;
use App\Modules\Teams\Domain\Contracts\TeamAudienceRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

final readonly class CommunicationTeamsService implements CommunicationTeamsDirectory
{
    public function __construct(private TeamAudienceRepository $records, private SportingPeopleDirectory $people,
        private TenantAuthorization $authorization) {}

    public function current(TenantContext $context): array
    {
        $this->authorization->authorize($context, 'communication.read');

        return $this->records->current($context->organizationId, $this->people->personForAccount($context->organizationId, $context->actorUserId));
    }

    public function choices(TenantContext $context, string $type, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorizeTarget($context, $type);

        return $this->records->choices($context->organizationId, $type, max(1, min(100000, $page)), max(1, min(100, $perPage)));
    }

    public function assertActive(TenantContext $context, string $type, string $id): void
    {
        $this->authorizeTarget($context, $type);
        $this->records->assertActive($context->organizationId, $type, $id);
    }

    private function authorizeTarget(TenantContext $context, string $type): void
    {
        $this->authorization->authorize($context, 'communication.read');
        $this->authorization->authorize($context, 'communication.write');
        if (! in_array($type, ['team', 'game'], true)) {
            throw new InvalidArgumentException('Unsupported communication target.');
        }
    }
}
