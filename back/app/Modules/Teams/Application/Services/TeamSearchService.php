<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\Teams\Application\Contracts\TeamSearch;
use App\Modules\Teams\Domain\Contracts\TeamRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\SearchPage;

final readonly class TeamSearchService implements TeamSearch
{
    public function __construct(private TeamRepository $records, private TenantAuthorization $authorization) {}

    public function search(TenantContext $context, string $term, ?string $after, int $size): SearchPage
    {
        $this->authorization->authorize($context, 'sports.read');

        return $this->records->search($context->organizationId, $term, $after, $size);
    }
}
