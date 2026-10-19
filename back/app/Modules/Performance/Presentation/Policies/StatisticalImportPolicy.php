<?php

namespace App\Modules\Performance\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class StatisticalImportPolicy
{
    public function __construct(private ObservationPolicy $observations, private TenantAuthorization $authorization) {}

    public function manage(Authenticatable $user, TenantContext $context): bool
    {
        return $this->observations->create($user, $context) && $this->authorization->allows($context, 'sports.statistics.import');
    }
}
