<?php

namespace App\Modules\People\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\SearchPage;

interface PeopleSearch
{
    public function search(TenantContext $context, string $term, ?string $after, int $size): SearchPage;
}
