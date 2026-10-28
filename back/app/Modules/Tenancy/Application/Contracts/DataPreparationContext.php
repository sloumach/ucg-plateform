<?php

namespace App\Modules\Tenancy\Application\Contracts;

interface DataPreparationContext
{
    public function resolve(string $organization, int $actor): TenantContext;
}
