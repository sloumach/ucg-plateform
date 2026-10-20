<?php

namespace App\Modules\Competition\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\SearchPage;

interface CompetitionSearch
{
    public function search(TenantContext $context, string $type, string $term, ?string $after, int $size): SearchPage;
}
