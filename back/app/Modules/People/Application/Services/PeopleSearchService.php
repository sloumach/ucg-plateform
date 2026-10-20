<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\PeopleSearch;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\SearchPage;

final readonly class PeopleSearchService implements PeopleSearch
{
    public function __construct(private PersonRepository $records, private TenantAuthorization $authorization) {}

    public function search(TenantContext $context, string $term, ?string $after, int $size): SearchPage
    {
        $this->authorization->authorize($context, 'sports.read');

        return $this->records->search($context->organizationId, $term, $after, $size);
    }
}
