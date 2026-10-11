<?php

namespace App\Modules\People\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;

interface PeopleDirectory
{
    public function find(TenantContext $context, string $personId): PersonRecord;
}
