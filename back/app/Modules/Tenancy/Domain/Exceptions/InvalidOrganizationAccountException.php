<?php

namespace App\Modules\Tenancy\Domain\Exceptions;

use App\Exceptions\DomainException;

final class InvalidOrganizationAccountException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('tenancy.errors.invalid_account'), 'ORGANIZATION_ACCOUNT_INVALID');
    }
}
