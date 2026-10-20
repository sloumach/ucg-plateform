<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\SearchPage;

interface TeamSearch
{
    public function search(TenantContext $context, string $term, ?string $after, int $size): SearchPage;
}
