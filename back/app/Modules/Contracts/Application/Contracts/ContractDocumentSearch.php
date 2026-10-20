<?php

namespace App\Modules\Contracts\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\SearchPage;

interface ContractDocumentSearch
{
    public function search(TenantContext $context, string $term, ?string $after, int $size): SearchPage;
}
