<?php

namespace App\Modules\Contracts\Application\Services;

use App\Modules\Contracts\Application\Contracts\ContractDocumentSearch;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\SearchPage;

final readonly class ContractDocumentSearchService implements ContractDocumentSearch
{
    public function __construct(private ContractDocumentRepository $records, private TenantAuthorization $authorization) {}

    public function search(TenantContext $context, string $term, ?string $after, int $size): SearchPage
    {
        $this->authorization->authorize($context, 'administrative.read');

        return $this->records->search($context->organizationId, $term, $after, $size, $this->authorization->allows($context, 'financial.read'));
    }
}
