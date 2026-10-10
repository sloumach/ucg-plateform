<?php

namespace App\Modules\Tenancy\Domain\Exceptions;

use App\Exceptions\DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;

final class TenantLimitExceededException extends DomainException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct(__('tenancy.errors.technical_limit_exceeded'), 'TENANT_LIMIT_EXCEEDED');
    }
}
