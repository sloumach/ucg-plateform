<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\PartnershipPeopleDirectory;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class PartnershipPeopleService implements PartnershipPeopleDirectory
{
    public function __construct(private PersonRepository $records, private TenantAuthorization $authorization) {}

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
