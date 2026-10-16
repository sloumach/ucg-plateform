<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\Teams\Application\Contracts\PartnershipTeamsDirectory;
use App\Modules\Teams\Domain\Contracts\TeamRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class PartnershipTeamsService implements PartnershipTeamsDirectory
{
    public function __construct(private TeamRepository $records, private TenantAuthorization $authorization) {}

    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'partnerships.read');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new \InvalidArgumentException('Partnership references must be paginated.');
        }

        return $this->records->partnershipPage($context->organizationId, $page, $perPage);
    }

    public function labels(TenantContext $context, array $ids): array
    {
        $this->authorization->authorize($context, 'partnerships.read');
        if (count($ids) > 100) {
            throw new \InvalidArgumentException('Partnership references must be bounded.');
        }

        return $this->records->partnershipLabels($context->organizationId, array_map('strtolower', $ids));
    }
}
