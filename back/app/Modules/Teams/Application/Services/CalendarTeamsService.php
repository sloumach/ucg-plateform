<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\Teams\Application\Contracts\CalendarTeamsDirectory;
use App\Modules\Teams\Domain\Contracts\TeamAudienceRepository;
use App\Modules\Teams\Domain\Contracts\TeamRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use LogicException;

final readonly class CalendarTeamsService implements CalendarTeamsDirectory
{
    public function __construct(private TeamAudienceRepository $audiences, private TeamRepository $records, private SportingPeopleDirectory $people, private TenantAuthorization $authorization) {}

    public function currentIds(TenantContext $context): array
    {
        $this->authorization->authorize($context, 'calendar.read');

        return $this->audiences->current($context->organizationId, $this->people->personForAccount($context->organizationId, $context->actorUserId))['teams'];
    }

    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->write($context);

        return $this->audiences->choices($context->organizationId, 'team', max(1, min(100000, $page)), max(1, min(100, $perPage)));
    }

    public function assertActive(TenantContext $context, string $id): void
    {
        $this->write($context);
        $this->audiences->assertActive($context->organizationId, 'team', $id);
    }

    public function labels(TenantContext $context, array $ids): array
    {
        $this->authorization->authorize($context, 'calendar.read');
        if (count($ids) > 100) {
            throw new LogicException('Calendar team references must be paginated.');
        }

        return $this->records->referenceLabels($context->organizationId, $ids);
    }

    private function write(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'calendar.read');
        $this->authorization->authorize($context, 'calendar.write');
    }
}
