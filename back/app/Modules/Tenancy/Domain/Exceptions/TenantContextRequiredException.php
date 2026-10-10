<?php

namespace App\Modules\Tenancy\Domain\Exceptions;

use App\Exceptions\DomainException;

final class TenantContextRequiredException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('tenancy.errors.context_required'), 'TENANT_CONTEXT_REQUIRED');
    }
}
