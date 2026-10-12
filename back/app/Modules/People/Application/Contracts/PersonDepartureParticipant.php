<?php

namespace App\Modules\People\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;

interface PersonDepartureParticipant
{
    /** Database-only work under account/organization/person locks; retries must be safe. No remote/filesystem calls. */
    public function closeForDeparture(TenantContext $context, string $personId, CarbonImmutable $at): void;
}
