<?php

namespace App\Modules\Reporting\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class ReportExportPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function manage(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->authorization->allows($context, 'reporting.export');
    }
}
